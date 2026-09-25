<?php
declare(strict_types=1);

/**
 * Premios automáticos a creadores, sobre la infraestructura de Awards (grant() idempotente: award_grants + user_badges):
 *
 *   - Hitos por nº de plantillas aprobadas (claves 'cms:{n}'): al aprobar una plantilla. Monedas e insignia configurables.
 *   - Cierre mensual (gancho de Awards::closeMonth, misma transacción): creadores con >= month_min_templates aprobadas y
 *     >= month_min_uses usos por OTROS en el mes reciben month_coins + insignia 'colaborador_mes' ('cmm:{ym}'); el n.º 1
 *     recibe además una MEJORA TEMPORAL de plan ('cmt:{ym}') en users.bonus_tier_*, sin tocar el plan comprado.
 *   - No retroactivo: los meses anteriores a settings.creators_launch_month no se premian.
 */
final class CreatorAwards
{
    public static function launchMonth(): string
    {
        return Creators::launchMonth();
    }

    /** Otorga los hitos de plantillas aprobadas ya alcanzados y aún no otorgados (una vez cada uno). Requiere transacción. */
    public static function evaluateMilestones(PDO $pdo, int $userId): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM templates WHERE owner_user_id = ? AND kind = 'utpl' AND review_status IN ('approved','withdrawn') AND reviewed_at IS NOT NULL");
        $st->execute([$userId]);
        $count = (int) $st->fetchColumn();
        $n = 0;
        foreach (Creators::config()['milestones'] as $m) {
            if ($m['count'] <= $count && Awards::grant($pdo, $userId, 'cms:' . $m['count'], 'creator_ms', (int) $m['coins'], (string) $m['badge'], '')) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Premios del mes 'YYYY-MM' (dentro de la transacción de Awards::closeMonth). Devuelve cuántas concesiones nuevas hubo.
     */
    public static function closeMonth(PDO $pdo, string $ym): int
    {
        $cfg = Creators::config();
        [$from, $to] = Awards::monthWindow($ym);
        $st = $pdo->prepare(
            "SELECT t.owner_user_id AS uid, COUNT(s.id) AS uses
               FROM templates t
               JOIN user_sites s ON s.template_id = t.id AND s.created_at >= ? AND s.created_at < ? AND s.user_id <> t.owner_user_id
               JOIN users u ON u.id = t.owner_user_id AND u.is_suspended = 0
              WHERE t.kind = 'utpl' AND t.owner_user_id IS NOT NULL AND t.review_status IN ('approved','withdrawn')
                AND (SELECT COUNT(*) FROM templates t2 WHERE t2.owner_user_id = t.owner_user_id AND t2.kind = 'utpl'
                        AND t2.review_status = 'approved' AND t2.reviewed_at < ?) >= ?
              GROUP BY t.owner_user_id HAVING uses >= ?
              ORDER BY uses DESC, t.owner_user_id ASC LIMIT 200"
        );
        $st->execute([$from, $to, $to, (int) $cfg['month_min_templates'], (int) $cfg['month_min_uses']]);
        $granted = 0;
        foreach ($st->fetchAll() as $i => $r) {
            $uid = (int) $r['uid'];
            $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$uid]);
            if (Awards::grant($pdo, $uid, 'cmm:' . $ym, 'creator_month', (int) $cfg['month_coins'], 'colaborador_mes', $ym)) {
                $granted++;
            }
            if ($i === 0 && $cfg['top_tier_slug'] !== '' && (int) $cfg['top_tier_days'] > 0
                && Awards::grant($pdo, $uid, 'cmt:' . $ym, 'creator_top', 0, '', $ym)) {
                self::applyBonusTier($pdo, $uid, (string) $cfg['top_tier_slug'], (int) $cfg['top_tier_days']);
                $granted++;
            }
        }
        return $granted;
    }

    /**
     * Concede una mejora temporal de plan: users.bonus_tier_id / bonus_tier_expires_at. No modifica el plan principal.
     * Con una mejora vigente de rango mayor no hace nada; del mismo plan suma los días al vencimiento; de menor rango la reemplaza.
     * Requiere transacción (bloquea la fila del usuario). Devuelve false si el plan no existe.
     */
    public static function applyBonusTier(PDO $pdo, int $userId, string $tierSlug, int $days, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $t = $pdo->prepare('SELECT id, sort_order FROM membership_tiers WHERE slug = ?');
        $t->execute([$tierSlug]);
        $tier = $t->fetch();
        $u = $pdo->prepare('SELECT bonus_tier_id, bonus_tier_expires_at FROM users WHERE id = ? FOR UPDATE');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$tier || !$user || $days < 1) {
            return false;
        }
        $base = $now;
        $curId = (int) ($user['bonus_tier_id'] ?? 0);
        $exp = $user['bonus_tier_expires_at'] ?? null;
        if ($curId > 0 && $exp !== null && !Access::isExpired((string) $exp, $now->getTimestamp())) {
            $c = $pdo->prepare('SELECT sort_order FROM membership_tiers WHERE id = ?');
            $c->execute([$curId]);
            $curRank = (int) $c->fetchColumn();
            if ($curRank > (int) $tier['sort_order']) {
                return true;   // ya tiene una mejora superior vigente
            }
            if ($curId === (int) $tier['id']) {
                $base = new DateTimeImmutable((string) $exp, new DateTimeZone('UTC'));
            }
        }
        $pdo->prepare('UPDATE users SET bonus_tier_id = ?, bonus_tier_expires_at = ? WHERE id = ?')
            ->execute([(int) $tier['id'], $base->modify('+' . $days . ' days')->format('Y-m-d H:i:s'), $userId]);
        return true;
    }
}
