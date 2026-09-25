<?php
declare(strict_types=1);

/**
 * Gestión de usuarios y páginas desde el panel: búsqueda, ajustes de monedas y plan, suspensión,
 * supresión de cuenta y moderación de páginas. Toda acción valida, opera en transacción corta y deja
 * una fila en admin_audit con el formato «user:<id> <acción> …» (filtrable en la ficha del usuario).
 * Los métodos lanzan InvalidArgumentException con un mensaje apto para mostrar al admin.
 */
final class AdminUsers
{
    public const FILTERS = [
        'all' => 'Todos', 'plan' => 'Plan vigente', 'expired' => 'Plan vencido', 'free' => 'Gratuitos',
        'suspended' => 'Suspendidos', 'admins' => 'Admins', 'pending' => 'Pagos pendientes',
    ];
    public const PAGE_FILTERS = ['all' => 'Todas', 'active' => 'Activas', 'expired' => 'Vencidas'];
    public const MAX_COINS_DELTA = 100000;
    public const MAX_MONTHS = 36;

    private const PLAN_ON = '(u.membership_tier_id IS NOT NULL AND (u.membership_expires_at IS NULL OR u.membership_expires_at > UTC_TIMESTAMP()))';

    /** Escapa % _ y el carácter de escape para un LIKE ... ESCAPE '|'. */
    public static function likeEscape(string $s): string
    {
        return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $s);
    }

    /**
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function search(string $q, string $filter, int $page, int $per = 25): array
    {
        $q = trim($q);
        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            if (ctype_digit($q) && strlen($q) <= 9) {
                $where[] = '(u.id = ? OR u.email LIKE ? ESCAPE \'|\')';
                $args[] = (int) $q;
            } else {
                $where[] = 'u.email LIKE ? ESCAPE \'|\'';
            }
            $args[] = '%' . self::likeEscape($q) . '%';
        }
        $where[] = match ($filter) {
            'plan'      => self::PLAN_ON,
            'expired'   => '(u.membership_tier_id IS NOT NULL AND u.membership_expires_at <= UTC_TIMESTAMP())',
            'free'      => 'NOT ' . self::PLAN_ON,
            'suspended' => 'u.is_suspended = 1',
            'admins'    => 'u.is_admin = 1',
            'pending'   => 'EXISTS (SELECT 1 FROM payments p WHERE p.user_id = u.id AND p.status = \'PENDING\')',
            default     => '1=1',
        };
        $w = implode(' AND ', $where);
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $per = max(1, min(100, $per));
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));

        $st = $pdo->prepare(
            'SELECT u.id, u.email, u.created_at, u.coins, u.is_admin, u.is_suspended, u.membership_tier_id, u.membership_expires_at,
                    mt.name AS tier_name, ' . self::PLAN_ON . ' AS plan_active,
                    (SELECT COUNT(*) FROM user_sites s WHERE s.user_id = u.id AND (s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP())) AS active_sites,
                    EXISTS (SELECT 1 FROM payments p WHERE p.user_id = u.id AND p.status = \'PENDING\') AS has_pending
               FROM users u LEFT JOIN membership_tiers mt ON mt.id = u.membership_tier_id
              WHERE ' . $w . ' ORDER BY u.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
        );
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $active = (int) $r['plan_active'] === 1;
            $r['plan_name'] = $active ? (string) $r['tier_name'] : null;
            $r['plan_expired'] = !$active && $r['membership_tier_id'] !== null;
            $rows[] = $r;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT id, email, is_premium, is_admin, is_suspended, suspended_reason, suspended_at, membership_tier_id, membership_expires_at, coins, created_at FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function sitesOf(int $userId): array
    {
        $st = db()->prepare('SELECT s.id, s.slug, s.created_at, s.expires_at, t.name AS template_name FROM user_sites s JOIN templates t ON t.id = s.template_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 50');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function paymentsOf(int $userId, int $limit = 10): array
    {
        $st = db()->prepare('SELECT p.reference, p.amount_in_cents, p.status, p.coins, p.created_at, mt.name AS tier_name, t.name AS template_name
                               FROM payments p LEFT JOIN membership_tiers mt ON mt.id = p.tier_id LEFT JOIN templates t ON t.id = p.template_id
                              WHERE p.user_id = ? ORDER BY p.id DESC LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** Acciones de admin sobre el usuario («user:<id> …»). @return list<array<string,mixed>> */
    public static function auditOf(int $userId, int $limit = 30): array
    {
        $st = db()->prepare('SELECT a.action, a.detail, a.created_at, u.email AS admin_email
                               FROM admin_audit a LEFT JOIN users u ON u.id = a.user_id
                              WHERE a.detail LIKE ? ESCAPE \'|\' ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute(['user:' . $userId . ' %']);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------- acciones ---

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new InvalidArgumentException('Escribe una nota/motivo de hasta 200 caracteres.');
        }
        return $reason;
    }

    private static function audit(int $adminId, string $action, string $detail): void
    {
        try {
            db()->prepare('INSERT INTO admin_audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')
                ->execute([$adminId, $action, mb_substr($detail, 0, 500), client_ip()]);
        } catch (Throwable $e) {
            error_log('admin_audit: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> fila bloqueada (FOR UPDATE) dentro de la transacción en curso */
    private static function lock(int $userId): array
    {
        $st = db()->prepare('SELECT id, email, is_admin, is_premium, membership_tier_id, membership_expires_at, coins FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) {
            throw new InvalidArgumentException('El usuario no existe.');
        }
        return $u;
    }

    /** Ajusta el saldo (delta ±) y lo anota en el libro. Devuelve el saldo nuevo. */
    public static function adjustCoins(int $userId, int $delta, string $reason, int $adminId): int
    {
        $reason = self::reason($reason);
        if ($delta === 0 || abs($delta) > self::MAX_COINS_DELTA) {
            throw new InvalidArgumentException('El ajuste debe ser distinto de 0 y de máximo ' . self::MAX_COINS_DELTA . ' monedas.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $u = self::lock($userId);
            if ($delta > 0) {
                Coins::credit($userId, $delta, 'admin_adjust', $reason);
            } elseif (!Coins::spend($userId, -$delta, 'admin_adjust', $reason)) {
                throw new InvalidArgumentException('El saldo (' . (int) $u['coins'] . ') no alcanza para restar ' . -$delta . ' monedas.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $new = Coins::balance($userId);
        self::audit($adminId, 'user.coins', sprintf('user:%d coins %+d (saldo %d) — %s', $userId, $delta, $new, $reason));
        return $new;
    }

    /**
     * Asigna o extiende un plan. Mismo plan vigente: suma meses al vencimiento; otro: parte de ahora.
     * No degrada a un plan inferior vigente salvo $force. Devuelve el nuevo vencimiento (UTC).
     */
    public static function grantPlan(int $userId, int $tierId, int $months, string $reason, int $adminId, bool $force = false, bool $bonus = false): string
    {
        $reason = self::reason($reason);
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('Los meses deben estar entre 1 y ' . self::MAX_MONTHS . '.');
        }
        $st = db()->prepare('SELECT id, name, sort_order, bonus_coins FROM membership_tiers WHERE id = ?');
        $st->execute([$tierId]);
        $tier = $st->fetch();
        if (!$tier) {
            throw new InvalidArgumentException('El plan no existe.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $u = self::lock($userId);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $cur = Access::userTier($u, $now->getTimestamp());
            if ($cur !== null && (int) $tier['sort_order'] < (int) $cur['sort_order'] && !$force) {
                throw new InvalidArgumentException('El usuario tiene un plan superior vigente (' . $cur['name'] . '). Marca «forzar» para reemplazarlo.');
            }
            $base = $now;
            if ($cur !== null && (int) $cur['id'] === $tierId && !empty($u['membership_expires_at'])) {
                $base = new DateTimeImmutable((string) $u['membership_expires_at'], new DateTimeZone('UTC'));
            }
            $until = $base->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET membership_tier_id = ?, is_premium = 1, membership_expires_at = ? WHERE id = ?')
                ->execute([$tierId, $until, $userId]);
            $bonusCoins = $bonus ? (int) $tier['bonus_coins'] : 0;
            if ($bonusCoins > 0) {
                Coins::credit($userId, $bonusCoins, 'tier_bonus', 'admin:' . $tier['name']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::audit($adminId, 'user.plan', sprintf('user:%d plan %s +%dm hasta %s%s%s — %s', $userId, $tier['name'], $months, $until, $force ? ' (forzado)' : '', $bonusCoins > 0 ? " bono +$bonusCoins" : '', $reason));
        return $until;
    }

    /** Vuelve la cuenta a gratuita de inmediato. */
    public static function removePlan(int $userId, string $reason, int $adminId): void
    {
        $reason = self::reason($reason);
        $st = db()->prepare('UPDATE users SET membership_tier_id = NULL, membership_expires_at = NULL, is_premium = 0 WHERE id = ?');
        $st->execute([$userId]);
        if (self::find($userId) === null) {
            throw new InvalidArgumentException('El usuario no existe.');
        }
        self::audit($adminId, 'user.unplan', sprintf('user:%d plan quitado — %s', $userId, $reason));
    }

    /** Suspende o reactiva. No aplica a admins ni a uno mismo. */
    public static function setSuspended(int $userId, bool $suspend, string $reason, int $adminId): void
    {
        $reason = self::reason($reason);
        $u = self::find($userId) ?? throw new InvalidArgumentException('El usuario no existe.');
        if ($suspend && ((int) $u['is_admin'] === 1 || $userId === $adminId)) {
            throw new InvalidArgumentException('No se puede suspender a un administrador ni a tu propia cuenta.');
        }
        if ($suspend) {
            db()->prepare('UPDATE users SET is_suspended = 1, suspended_reason = ?, suspended_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$reason, $userId]);
        } else {
            db()->prepare('UPDATE users SET is_suspended = 0, suspended_reason = NULL, suspended_at = NULL WHERE id = ?')->execute([$userId]);
        }
        self::audit($adminId, $suspend ? 'user.suspend' : 'user.unsuspend', sprintf('user:%d %s — %s', $userId, $suspend ? 'suspendido' : 'reactivado', $reason));
    }

    /**
     * Elimina la cuenta y, por FK en cascada, sus páginas, creaciones, monedas Y pagos (payments.user_id
     * es ON DELETE CASCADE). admin_audit conserva el rastro (user_id -> NULL).
     */
    public static function deleteAccount(int $userId, string $confirmEmail, string $reason, int $adminId): void
    {
        $reason = self::reason($reason);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $u = self::lock($userId);
            if ((int) $u['is_admin'] === 1 || $userId === $adminId) {
                throw new InvalidArgumentException('No se puede eliminar a un administrador ni a tu propia cuenta.');
            }
            if (!hash_equals(strtolower((string) $u['email']), strtolower(trim($confirmEmail)))) {
                throw new InvalidArgumentException('El correo escrito no coincide con el de la cuenta.');
            }
            $sites = (int) $pdo->query('SELECT COUNT(*) FROM user_sites WHERE user_id = ' . $userId)->fetchColumn();
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::audit($adminId, 'user.delete', sprintf('user:%d eliminada (%s), %d páginas, %d monedas — %s', $userId, $u['email'], $sites, (int) $u['coins'], $reason));
    }

    // ---------------------------------------------------------- páginas ---

    /** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} */
    public static function pages(string $q, string $filter, int $page, int $per = 25): array
    {
        $q = trim($q);
        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            $like = '%' . self::likeEscape($q) . '%';
            $where[] = '(s.slug LIKE ? ESCAPE \'|\' OR u.email LIKE ? ESCAPE \'|\' OR s.data LIKE ? ESCAPE \'|\')';
            array_push($args, $like, $like, $like);
        }
        $where[] = match ($filter) {
            'active'  => '(s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP())',
            'expired' => 's.expires_at <= UTC_TIMESTAMP()',
            default   => '1=1',
        };
        $w = implode(' AND ', $where);
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_sites s JOIN users u ON u.id = s.user_id WHERE $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $per = max(1, min(100, $per));
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));
        $st = $pdo->prepare('SELECT s.id, s.slug, s.created_at, s.expires_at, (s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP()) AS is_active,
                                    t.name AS template_name, u.id AS user_id, u.email, u.is_suspended
                               FROM user_sites s JOIN users u ON u.id = s.user_id JOIN templates t ON t.id = s.template_id
                              WHERE ' . $w . ' ORDER BY s.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function deletePage(int $siteId, string $reason, int $adminId): void
    {
        $reason = self::reason($reason);
        $st = db()->prepare('SELECT s.slug, s.user_id, u.email FROM user_sites s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
        $st->execute([$siteId]);
        $s = $st->fetch() ?: throw new InvalidArgumentException('La página ya no existe.');
        db()->prepare('DELETE FROM user_sites WHERE id = ?')->execute([$siteId]);
        self::audit($adminId, 'page.delete', sprintf('user:%d página %s eliminada (%s) — %s', (int) $s['user_id'], $s['slug'], $s['email'], $reason));
    }
}
