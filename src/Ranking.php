<?php
declare(strict_types=1);

/**
 * Top de donadores: clasificación por apoyo financiero (mes calendario de El Salvador o histórico).
 *
 * Apoyo = suma de payments.amount_in_cents de pagos APPROVED, cumplidos (fulfilled_at), de monto > 0 y
 * método WOMPI/MANUAL. Cupones 100 % (PROMO / monto 0), pendientes, rechazados, anulados y no cumplidos NO cuentan.
 * Desempate: mayor total, primer pago cumplido más antiguo del periodo, id de usuario menor.
 * Suspendidos no aparecen (los eliminados desaparecen por ON DELETE CASCADE).
 *
 * PRIVACIDAD: los métodos públicos (publicTop, standing) jamás devuelven correo, id ni datos reales;
 * solo el alias de quien activó show_in_rankings. rows() con $withEmail es SOLO para el panel admin.
 */
final class Ranking
{
    public const VIEWS = ['mes', 'historico'];
    public const ANON = 'Donador anónimo';
    public const ALIAS_MIN = 3;
    public const ALIAS_MAX = 30;

    /** Fragmentos que no pueden formar parte de un alias (suplantación de la plataforma o del equipo). */
    private const RESERVED_ALIASES = ['admin', 'administrador', 'lovepages', 'pdsx', 'soporte', 'support', 'moderador', 'moderator', 'staff', 'oficial', 'official', 'donadoranonimo', 'anonimo'];

    /** Condición común de pago que cuenta como apoyo (alias p). */
    private const PAID = "p.status = 'APPROVED' AND p.fulfilled_at IS NOT NULL AND p.amount_in_cents > 0 AND p.method IN ('WOMPI', 'MANUAL')";

    /** @return array{0:string,1:string} [desde, hasta) en UTC */
    public static function window(string $view, ?int $now = null): array
    {
        return $view === 'mes' ? Access::monthBounds($now) : ['1970-01-01 00:00:00', '9999-12-31 00:00:00'];
    }

    /**
     * Filas ordenadas del periodo. Uso interno / admin: incluye user_id (y correo si $withEmail).
     *
     * @return list<array{user_id:int, total:int, first_at:string, display_name:?string, show:int, email?:string}>
     */
    public static function rows(string $from, string $to, int $limit, bool $withEmail = false): array
    {
        $sql = 'SELECT p.user_id, SUM(p.amount_in_cents) AS total, MIN(p.fulfilled_at) AS first_at, u.display_name, u.show_in_rankings'
            . ($withEmail ? ', u.email' : '')
            . ' FROM payments p JOIN users u ON u.id = p.user_id AND u.is_suspended = 0'
            . ' WHERE ' . self::PAID . ' AND p.fulfilled_at >= ? AND p.fulfilled_at < ?'
            . ' GROUP BY p.user_id, u.display_name, u.show_in_rankings' . ($withEmail ? ', u.email' : '')
            . ' ORDER BY total DESC, first_at ASC, p.user_id ASC LIMIT ' . max(1, min(1000, $limit));
        $st = db()->prepare($sql);
        $st->execute([$from, $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $row = [
                'user_id'      => (int) $r['user_id'],
                'total'        => (int) $r['total'],
                'first_at'     => (string) $r['first_at'],
                'display_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
                'show'         => (int) $r['show_in_rankings'],
            ];
            if ($withEmail) {
                $row['email'] = (string) $r['email'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Top público: posición, nombre visible (alias o null = anónimo), total e insignias. Sin id ni correo.
     *
     * @return list<array{pos:int, name:?string, cents:int, badges:list<string>}>
     */
    public static function publicTop(string $view, int $limit = 10, ?int $now = null): array
    {
        [$from, $to] = self::window($view, $now);
        $rows = self::rows($from, $to, $limit);
        $badges = Awards::badgeKeysFor(array_column($rows, 'user_id'));
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [
                'pos'    => $i + 1,
                'name'   => self::visibleName($r['display_name'], $r['show']),
                'cents'  => $r['total'],
                'badges' => $badges[$r['user_id']] ?? [],
            ];
        }
        return $out;
    }

    /** Alias visible: solo con opt-in Y alias válido; si no, null (se pinta «Donador anónimo»). */
    public static function visibleName(?string $displayName, int $show): ?string
    {
        if ($show !== 1 || $displayName === null) {
            return null;
        }
        return self::validateAlias($displayName)['error'] === null ? $displayName : null;
    }

    /** Total del usuario en el periodo (0 si no apoyó). */
    public static function totalFor(int $userId, string $from, string $to): int
    {
        $st = db()->prepare('SELECT COALESCE(SUM(p.amount_in_cents), 0) FROM payments p WHERE p.user_id = ? AND ' . self::PAID . ' AND p.fulfilled_at >= ? AND p.fulfilled_at < ?');
        $st->execute([$userId, $from, $to]);
        return (int) $st->fetchColumn();
    }

    /**
     * Posición y total del propio usuario (aunque no esté en el top). null si no tiene apoyo en el periodo
     * o está suspendido. No expone a nadie más.
     *
     * @return array{pos:int, cents:int}|null
     */
    public static function standing(int $userId, string $view, ?int $now = null): ?array
    {
        [$from, $to] = self::window($view, $now);
        $st = db()->prepare('SELECT SUM(p.amount_in_cents) AS total, MIN(p.fulfilled_at) AS first_at
                               FROM payments p JOIN users u ON u.id = p.user_id AND u.is_suspended = 0
                              WHERE p.user_id = ? AND ' . self::PAID . ' AND p.fulfilled_at >= ? AND p.fulfilled_at < ?');
        $st->execute([$userId, $from, $to]);
        $me = $st->fetch();
        if (!$me || $me['total'] === null) {
            return null;
        }
        $total = (int) $me['total'];
        $first = (string) $me['first_at'];
        $st = db()->prepare('SELECT COUNT(*) + 1 FROM (
                SELECT p.user_id, SUM(p.amount_in_cents) AS total, MIN(p.fulfilled_at) AS first_at
                  FROM payments p JOIN users u ON u.id = p.user_id AND u.is_suspended = 0
                 WHERE ' . self::PAID . ' AND p.fulfilled_at >= ? AND p.fulfilled_at < ?
                 GROUP BY p.user_id) t
              WHERE t.total > ? OR (t.total = ? AND (t.first_at < ? OR (t.first_at = ? AND t.user_id < ?)))');
        $st->execute([$from, $to, $total, $total, $first, $first, $userId]);
        return ['pos' => (int) $st->fetchColumn(), 'cents' => $total];
    }

    /**
     * Valida y normaliza un alias: 3–30 caracteres; letras (con tildes), números, espacios, . _ -;
     * sin URLs, @ ni HTML. Recorta y colapsa espacios.
     *
     * @return array{value:string, error:?string}
     */
    public static function validateAlias(string $raw): array
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $bad = static fn(string $m): array => ['value' => $v, 'error' => $m];
        $len = mb_strlen($v);
        if ($len < self::ALIAS_MIN || $len > self::ALIAS_MAX) {
            return $bad('El alias debe tener entre ' . self::ALIAS_MIN . ' y ' . self::ALIAS_MAX . ' caracteres.');
        }
        if (!preg_match('/^[\p{L}\p{N} ._-]+$/u', $v) || !preg_match('/\p{L}/u', $v)) {
            return $bad('Usa solo letras, números, espacios y . _ - (con al menos una letra).');
        }
        if (preg_match('/https?|www|\.(com|net|org|io|sv|es|me|co|app|xyz|info|ly|gg)\b/iu', $v)) {
            return $bad('El alias no puede parecer un enlace.');
        }
        // Evita suplantar a la plataforma o a su equipo (se compara sin espacios ni signos, en minúsculas y sin tildes).
        $flat = strtr(mb_strtolower((string) preg_replace('/[\s._-]+/u', '', $v)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        foreach (self::RESERVED_ALIASES as $r) {
            if (str_contains($flat, $r)) {
                return $bad('Ese alias no está disponible. Elige otro.');
            }
        }
        return ['value' => $v, 'error' => null];
    }

    /** ¿Ya lo usa otra cuenta? (la colación utf8mb4_unicode_ci ignora mayúsculas y tildes). */
    public static function aliasTaken(string $alias, ?int $exceptUserId = null): bool
    {
        $st = db()->prepare('SELECT 1 FROM users WHERE display_name = ? AND id <> ? LIMIT 1');
        $st->execute([$alias, $exceptUserId ?? 0]);
        return $st->fetchColumn() !== false;
    }

    /** Costo por defecto (monedas) de CAMBIAR el alias; configurable en el panel (ajuste `alias_change_cost`). */
    public const DEFAULT_ALIAS_CHANGE_COST = 30;

    /** Monedas que cuesta cambiar el alias (0 = gratis). El primer alias y ocultarlo del ranking nunca cuestan. */
    public static function aliasChangeCost(): int
    {
        $raw = trim(Admin::setting('alias_change_cost'));
        return $raw !== '' && ctype_digit($raw) ? min(10000, (int) $raw) : self::DEFAULT_ALIAS_CHANGE_COST;
    }

    /** ¿Dos alias son "el mismo" (solo cambian mayúsculas, tildes o espacios/signos)? */
    public static function sameAlias(string $a, string $b): bool
    {
        $flat = static fn(string $x): string => strtr(mb_strtolower((string) preg_replace('/[\s._-]+/u', '', $x)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        return $flat($a) === $flat($b);
    }

    /**
     * Guarda la preferencia de aparecer en el ranking y, si se pide, un alias nuevo.
     *  - Mostrar/ocultar el alias es SIEMPRE gratis (retirar el consentimiento no puede costar).
     *  - El primer alias (cuentas antiguas sin alias) es gratis; CAMBIAR un alias existente cuesta
     *    aliasChangeCost() monedas, cobradas en la misma transacción que el cambio (fila del usuario bloqueada).
     *  - Un alias vacío NO borra el actual (evita "borrar y volver a poner gratis").
     *  - Cambios que solo alteran mayúsculas/tildes se consideran el mismo alias y son gratis.
     *
     * @return array{ok:bool, error:?string, charged:int}
     */
    public static function saveOptIn(int $userId, bool $show, string $alias): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'charged' => 0];
        $alias = trim($alias);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT display_name, coins FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$userId]);
            $row = $st->fetch();
            if (!$row) {
                $pdo->rollBack();
                return $fail('Cuenta no encontrada.');
            }
            $current = (string) ($row['display_name'] ?? '');
            $value = $current !== '' ? $current : null;
            $charged = 0;

            if ($alias !== '' || ($show && $current === '')) {
                $v = self::validateAlias($alias);
                if ($v['error'] !== null) {
                    $pdo->rollBack();
                    return $fail($v['error']);
                }
                if ($current === '' || !self::sameAlias($current, $v['value']) || $current !== $v['value']) {
                    if (self::aliasTaken($v['value'], $userId)) {
                        $pdo->rollBack();
                        return $fail('Ese alias ya está en uso. Elige otro.');
                    }
                    $isChange = $current !== '' && !self::sameAlias($current, $v['value']);
                    $cost = $isChange ? self::aliasChangeCost() : 0;
                    if ($cost > 0 && !Coins::spend($userId, $cost, 'alias_change', $current . ' -> ' . $v['value'])) {
                        $pdo->rollBack();
                        return $fail('Cambiar el alias cuesta ' . $cost . ' monedas y tu saldo es ' . (int) $row['coins'] . '. Puedes recargar en la Tienda.');
                    }
                    $charged = $cost;
                    $value = $v['value'];
                }
            }
            $pdo->prepare('UPDATE users SET display_name = ?, show_in_rankings = ? WHERE id = ?')->execute([$value, $show ? 1 : 0, $userId]);
            $pdo->commit();
            return ['ok' => true, 'error' => null, 'charged' => $charged];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {   // índice único de alias (carrera entre dos cambios simultáneos)
                return $fail('Ese alias ya está en uso. Elige otro.');
            }
            throw $e;
        }
    }

    /** Preferencia actual del usuario. @return array{alias:string, show:bool} */
    public static function optIn(int $userId): array
    {
        $st = db()->prepare('SELECT display_name, show_in_rankings FROM users WHERE id = ?');
        $st->execute([$userId]);
        $r = $st->fetch();
        return ['alias' => $r ? (string) ($r['display_name'] ?? '') : '', 'show' => $r && (int) $r['show_in_rankings'] === 1];
    }

    /** Admin: borra un alias abusivo y saca al usuario del ranking público. */
    public static function clearAlias(int $userId): bool
    {
        $st = db()->prepare('UPDATE users SET display_name = NULL, show_in_rankings = 0 WHERE id = ? AND (display_name IS NOT NULL OR show_in_rankings = 1)');
        $st->execute([$userId]);
        return $st->rowCount() > 0;
    }
}
