<?php
declare(strict_types=1);

/**
 * Reparto de ingresos a creadores (ledger template_earnings) en dos tiempos, sin deadlocks:
 *
 *   1. record(): DENTRO de la transacción del comprador (Sites::create/renew), tras cobrar o cubrir por cupo. Solo inserta una
 *      fila 'pending' (INSERT IGNORE, UNIQUE template_id+ref = idempotente). NO toca la fila del creador (la tabla no
 *      tiene FK a creator_id a propósito): si dos usuarios se compran plantillas mutuamente, no hay bloqueos cruzados.
 *   2. settle(): DESPUÉS del commit, en su propia transacción. Bloquea al creador y luego sus ganancias pendientes,
 *      acredita la suma con Coins::credit (razón 'creator_share', libro coin_transactions) y las marca 'paid'.
 *      Idempotente y reintentable; se invoca tras cada venta, al abrir los paneles y desde bin/settle_creators.php.
 *
 * share_coins = floor(base * share_pct / 100). Base: monedas realmente gastadas (kind 'coins') o el precio nominal en
 * monedas cuando el cupo de membresía cubre el uso (kind 'quota', solo si share_on_quota_unlock). Nunca hay comisión por
 * autocompra. La plataforma financia la parte de los usos por cupo.
 */
final class CreatorEarnings
{
    /**
     * Registra la ganancia de un uso. Devuelve el id del creador a liquidar tras el commit, o null si no procede.
     *
     * @param array{id:int, owner:?int, status:string, active:bool, price:int} $ctx de Creators::saleContext()
     */
    public static function record(PDO $pdo, array $ctx, int $buyerId, int $siteId, string $ref, string $kind, int $baseCoins): ?int
    {
        $cfg = Creators::config();
        $owner = $ctx['owner'];
        if ($owner === null || $owner === $buyerId || $ctx['status'] !== 'approved' || $baseCoins <= 0) {
            return null;
        }
        if ($kind === 'quota' && !$cfg['share_on_quota_unlock']) {
            return null;
        }
        $share = intdiv($baseCoins * (int) $cfg['share_pct'], 100);
        if ($share <= 0) {
            return null;
        }
        $st = $pdo->prepare('INSERT IGNORE INTO template_earnings (template_id, creator_id, buyer_id, site_id, ref, kind, base_coins, share_coins)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$ctx['id'], $owner, $buyerId, $siteId, mb_substr($ref, 0, 80), $kind, $baseCoins, $share]);
        return $st->rowCount() === 1 ? $owner : null;
    }

    /** Ganancias pendientes del creador, en monedas. */
    public static function pendingCoins(int $creatorId): int
    {
        $st = db()->prepare("SELECT COALESCE(SUM(share_coins), 0) FROM template_earnings WHERE creator_id = ? AND status = 'pending'");
        $st->execute([$creatorId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Paga las ganancias pendientes del creador. Devuelve las monedas acreditadas (0 si no había nada).
     * Nunca lanza: un fallo se registra y queda pendiente para el siguiente intento.
     */
    public static function settle(int $creatorId): int
    {
        try {
            if (self::pendingCoins($creatorId) === 0) {
                return 0;   // camino barato, sin transacción ni bloqueos
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Orden fijo: primero la fila del usuario (igual que las compras), después sus ganancias.
                $u = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $u->execute([$creatorId]);
                if ($u->fetchColumn() === false) {
                    $pdo->rollBack();
                    return 0;   // usuario borrado: ganancias huérfanas, se ignoran
                }
                $st = $pdo->prepare("SELECT id, share_coins FROM template_earnings WHERE creator_id = ? AND status = 'pending' ORDER BY id FOR UPDATE");
                $st->execute([$creatorId]);
                $rows = $st->fetchAll();
                if ($rows === []) {
                    $pdo->rollBack();
                    return 0;
                }
                $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
                $sum = array_sum(array_map(static fn (array $r): int => (int) $r['share_coins'], $rows));
                if ($sum > 0) {
                    Coins::credit($creatorId, $sum, 'creator_share', 'earn:' . $ids[0] . '-' . end($ids) . 'x' . count($ids));
                }
                $in = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE template_earnings SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE status = 'pending' AND id IN ($in)")->execute($ids);
                $pdo->commit();
                return $sum;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('CreatorEarnings::settle(' . $creatorId . '): ' . $e->getMessage());
            return 0;
        }
    }

    /** Liquida a todos los creadores con ganancias pendientes (cron/panel). @return int monedas acreditadas en total */
    public static function settleAll(): int
    {
        $ids = db()->query("SELECT DISTINCT creator_id FROM template_earnings WHERE status = 'pending' LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
        $total = 0;
        foreach ($ids as $id) {
            $total += self::settle((int) $id);
        }
        return $total;
    }

    /** @return array{pending:int, paid:int} totales del creador */
    public static function totals(int $creatorId): array
    {
        $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN share_coins END), 0) AS p,
                                    COALESCE(SUM(CASE WHEN status = 'paid' THEN share_coins END), 0) AS d
                               FROM template_earnings WHERE creator_id = ?");
        $st->execute([$creatorId]);
        $r = $st->fetch() ?: ['p' => 0, 'd' => 0];
        return ['pending' => (int) $r['p'], 'paid' => (int) $r['d']];
    }
}
