<?php
declare(strict_types=1);

/**
 * Cofre de Aniversario: una página que sigue ACTIVA (sin vencer, por renovaciones) cumple 1 mes, 6 meses o 1 año
 * desde su creación y genera un cofre con monedas. sync() los crea de forma perezosa (INSERT IGNORE sobre
 * UNIQUE site_id+hito: nunca dos veces) y open() los abre con la fila bloqueada, una sola vez.
 */
final class Chests
{
    /** hito => [nombre, días desde la creación, monedas]. */
    public const MILESTONES = [
        '1m'  => ['1 mes',    30,  20],
        '6m'  => ['6 meses',  182, 60],
        '12m' => ['1 año',    365, 150],
    ];

    /**
     * Crea los cofres que ya corresponden (páginas activas con la antigüedad del hito).
     *
     * @return int cofres nuevos
     */
    public static function sync(int $userId, ?DateTimeImmutable $now = null): int
    {
        $pdo = db();
        $utc = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $nowSql = $utc->format('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT id, created_at FROM user_sites WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?)');
        $st->execute([$userId, $nowSql]);
        $new = 0;
        $ins = $pdo->prepare('INSERT IGNORE INTO anniversary_chests (user_id, site_id, milestone, coins) VALUES (?, ?, ?, ?)');
        foreach ($st->fetchAll() as $site) {
            $created = new DateTimeImmutable((string) $site['created_at'], new DateTimeZone('UTC'));
            foreach (self::MILESTONES as $key => [, $days, $coins]) {
                if ($created->modify("+$days days") <= $utc) {
                    $ins->execute([$userId, (int) $site['id'], $key, $coins]);
                    $new += $ins->rowCount();
                }
            }
        }
        return $new;
    }

    /** Cofres sin abrir del usuario (sincroniza antes). @return list<array<string,mixed>> */
    public static function available(int $userId, ?DateTimeImmutable $now = null): array
    {
        self::sync($userId, $now);
        $st = db()->prepare('SELECT id, site_id, milestone, coins FROM anniversary_chests WHERE user_id = ? AND opened_at IS NULL ORDER BY id');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return array{ok:bool, coins:int, error:?string} */
    public static function open(int $userId, int $chestId): array
    {
        $pdo = db();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT id, milestone, coins, opened_at FROM anniversary_chests WHERE id = ? AND user_id = ? FOR UPDATE');
            $st->execute([$chestId, $userId]);
            $c = $st->fetch();
            if (!$c) {
                $res = ['ok' => false, 'coins' => 0, 'error' => 'Cofre no encontrado.'];
            } elseif ($c['opened_at'] !== null) {
                $res = ['ok' => false, 'coins' => 0, 'error' => 'Ese cofre ya se abrió.'];
            } else {
                $up = $pdo->prepare('UPDATE anniversary_chests SET opened_at = UTC_TIMESTAMP() WHERE id = ? AND opened_at IS NULL');
                $up->execute([$chestId]);
                if ($up->rowCount() !== 1) {
                    $res = ['ok' => false, 'coins' => 0, 'error' => 'Ese cofre ya se abrió.'];
                } else {
                    Coins::credit($userId, (int) $c['coins'], 'chest', (string) $c['milestone'] . '-' . $chestId);
                    $res = ['ok' => true, 'coins' => (int) $c['coins'], 'error' => null];
                }
            }
            if ($own) {
                $pdo->commit();
            }
            return $res;
        } catch (Throwable $e) {
            if ($own) {
                try {
                    $pdo->rollBack();
                } catch (PDOException) {
                    // ya no había transacción activa (p. ej. falló el commit): se relanza el error original
                }
            }
            throw $e;
        }
    }

    public static function label(string $milestone): string
    {
        return self::MILESTONES[$milestone][0] ?? $milestone;
    }
}
