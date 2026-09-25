<?php
declare(strict_types=1);

require_once __DIR__ . '/Ranking.php';
require_once __DIR__ . '/CreatorAwards.php';

/**
 * Premios automáticos del top de donadores: top mensual e hitos de gasto acumulado, con monedas e insignias.
 *
 * IDEMPOTENCIA: toda concesión pasa por grant(): INSERT IGNORE en award_grants (UNIQUE user_id+grant_key);
 * solo si la fila se inserta se acreditan las monedas (Coins::credit, misma transacción) y se anota la insignia.
 * Los meses se cierran de forma perezosa (closePendingMonths) protegidos con GET_LOCK y awards_closed_months.
 * La configuración vive en settings.awards_config (JSON) con valores por defecto en código y validación estricta.
 */
final class Awards
{
    public const CONFIG_KEY = 'awards_config';
    public const LAUNCH_KEY = 'awards_launch_month';
    private const LOCK_NAME = 'lovepages_awards_close';
    private const ALL_TIME = ['1970-01-01 00:00:00', '9999-12-31 00:00:00'];

    public const MAX_TOP_N = 10;
    public const MAX_COINS = 10000;
    public const MAX_MILESTONES = 6;
    public const MAX_CENTS = 1000000;   // $10,000

    /** Valores por defecto: top 3 (100/50/25 monedas), mínimo $1.00 al mes e hitos $10/$25/$50/$100. */
    public const DEFAULTS = [
        'top_n'           => 3,
        'month_coins'     => [100, 50, 25],
        'min_month_cents' => 100,
        'milestones'      => [
            ['cents' => 1000,  'coins' => 10],
            ['cents' => 2500,  'coins' => 30],
            ['cents' => 5000,  'coins' => 70],
            ['cents' => 10000, 'coins' => 160],
        ],
    ];

    /** Catálogo de insignias: clave => [nombre, descripción, icono en assets/img/awards/]. */
    public const BADGES = [
        'mecenas_1'      => ['Mecenas del mes', 'Primer lugar del top de donadores de un mes.', 'mecenas-1'],
        'mecenas_2'      => ['Mecenas del mes · 2.º', 'Segundo lugar del top de donadores de un mes.', 'mecenas-2'],
        'mecenas_3'      => ['Mecenas del mes · 3.º', 'Tercer lugar del top de donadores de un mes.', 'mecenas-3'],
        'top_mes'        => ['Top del mes', 'Entre los mejores donadores de un mes.', 'medalla'],
        'apoyo_bronce'   => ['Apoyo Bronce', 'Primer hito de apoyo acumulado.', 'apoyo-bronce'],
        'apoyo_plata'    => ['Apoyo Plata', 'Segundo hito de apoyo acumulado.', 'apoyo-plata'],
        'apoyo_oro'      => ['Apoyo Oro', 'Tercer hito de apoyo acumulado.', 'apoyo-oro'],
        'apoyo_diamante' => ['Apoyo Diamante', 'Cuarto hito de apoyo acumulado.', 'apoyo-diamante'],
        'apoyo_hito'     => ['Gran apoyo', 'Hito de apoyo acumulado.', 'medalla'],
        'creador'          => ['Creador', 'Publicó su primera plantilla en la Galería.', 'creador'],
        'creador_estrella' => ['Creador Estrella', 'Tiene varias plantillas aprobadas en la Galería.', 'creador-estrella'],
        'colaborador_mes'  => ['Colaborador destacado del mes', 'Sus plantillas fueron de las más usadas de un mes.', 'colaborador-mes'],
    ];

    /** Insignia por posición de hito (0 = primero); más allá del cuarto, la genérica. */
    private const MILESTONE_BADGES = ['apoyo_bronce', 'apoyo_plata', 'apoyo_oro', 'apoyo_diamante'];

    private static ?array $cfg = null;

    // -------------------------------------------------------- configuración ---

    /** Configuración vigente (validada). Si lo guardado es inválido o falta, los valores por defecto. */
    public static function config(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $cfg = self::DEFAULTS;
        $json = self::setting(self::CONFIG_KEY);
        if ($json !== null && is_array($raw = json_decode($json, true))) {
            $v = self::validateConfig($raw);
            if ($v['config'] !== null) {
                $cfg = $v['config'];
            }
        }
        return self::$cfg = $cfg;
    }

    public static function flushCache(): void
    {
        self::$cfg = null;
    }

    /**
     * Validación estricta: enteros acotados, top_n <= 10, monedas <= 10000, hitos crecientes y únicos.
     *
     * @param array<mixed> $raw
     * @return array{config:?array<string,mixed>, errors:list<string>}
     */
    public static function validateConfig(array $raw): array
    {
        $errors = [];
        $int = static function (mixed $v, int $min, int $max): ?int {
            if (is_string($v) && preg_match('/^\d{1,9}$/', trim($v))) {
                $v = (int) trim($v);
            }
            return is_int($v) && $v >= $min && $v <= $max ? $v : null;
        };
        $n = $int($raw['top_n'] ?? null, 1, self::MAX_TOP_N);
        if ($n === null) {
            $errors[] = 'El tamaño del top mensual debe ser un entero entre 1 y ' . self::MAX_TOP_N . '.';
        }
        $coins = [];
        $mc = $raw['month_coins'] ?? null;
        if ($n !== null) {
            for ($i = 0; $i < $n; $i++) {
                $c = is_array($mc) ? $int($mc[$i] ?? null, 0, self::MAX_COINS) : null;
                if ($c === null) {
                    $errors[] = 'Monedas del puesto ' . ($i + 1) . ': entero entre 0 y ' . self::MAX_COINS . '.';
                }
                $coins[] = (int) $c;
            }
        }
        $min = $int($raw['min_month_cents'] ?? null, 1, self::MAX_CENTS);
        if ($min === null) {
            $errors[] = 'El gasto mínimo del mes debe estar entre $0.01 y $' . number_format(self::MAX_CENTS / 100, 2) . '.';
        }
        $ms = [];
        $rawMs = $raw['milestones'] ?? [];
        if (!is_array($rawMs) || count($rawMs) > self::MAX_MILESTONES) {
            $errors[] = 'Como máximo ' . self::MAX_MILESTONES . ' hitos.';
        } else {
            $prev = 0;
            foreach (array_values($rawMs) as $i => $m) {
                $cents = is_array($m) ? $int($m['cents'] ?? null, 1, self::MAX_CENTS) : null;
                $mcoins = is_array($m) ? $int($m['coins'] ?? null, 0, self::MAX_COINS) : null;
                if ($cents === null || $mcoins === null) {
                    $errors[] = 'Hito ' . ($i + 1) . ': monto entre $0.01 y $' . number_format(self::MAX_CENTS / 100, 2) . ' y monedas entre 0 y ' . self::MAX_COINS . '.';
                    continue;
                }
                if ($cents <= $prev) {
                    $errors[] = 'Los hitos deben ser crecientes y sin repetir (revisa el hito ' . ($i + 1) . ').';
                    continue;
                }
                $prev = $cents;
                $ms[] = ['cents' => $cents, 'coins' => $mcoins];
            }
        }
        if ($errors !== []) {
            return ['config' => null, 'errors' => $errors];
        }
        return ['config' => ['top_n' => (int) $n, 'month_coins' => $coins, 'min_month_cents' => (int) $min, 'milestones' => $ms], 'errors' => []];
    }

    /** @param array<mixed> $raw @return list<string> errores (vacío = guardado) */
    public static function saveConfig(array $raw): array
    {
        $v = self::validateConfig($raw);
        if ($v['config'] === null) {
            return $v['errors'];
        }
        self::putSetting(self::CONFIG_KEY, json_encode($v['config'], JSON_THROW_ON_ERROR));
        self::flushCache();
        return [];
    }

    public static function setting(string $key): ?string
    {
        $st = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public static function putSetting(string $key, string $value): void
    {
        db()->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
                       ON DUPLICATE KEY UPDATE `value` = ?, updated_at = UTC_TIMESTAMP()')->execute([$key, $value, $value]);
    }

    // ---------------------------------------------------------------- meses ---

    /** 'YYYY-MM' del mes calendario de El Salvador que contiene $now. */
    public static function monthKey(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('America/El_Salvador'))->format('Y-m');
    }

    /** @return array{0:string,1:string} ventana UTC [desde, hasta) del mes 'YYYY-MM' */
    public static function monthWindow(string $ym): array
    {
        $mid = new DateTimeImmutable($ym . '-15 12:00:00', new DateTimeZone('America/El_Salvador'));
        return Access::monthBounds($mid->getTimestamp());
    }

    public static function launchMonth(): string
    {
        $v = self::setting(self::LAUNCH_KEY);
        if ($v === null || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) {
            $v = self::monthKey();
            self::putSetting(self::LAUNCH_KEY, $v);
        }
        return $v;
    }

    /** Meses terminados y sin cerrar, desde el de lanzamiento. @return list<string> */
    public static function pendingMonths(PDO $pdo, ?int $now = null): array
    {
        $current = self::monthKey($now);
        $closed = $pdo->query('SELECT ym FROM awards_closed_months')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        $d = new DateTimeImmutable(self::launchMonth() . '-01');
        for ($i = 0; $i < 240 && $d->format('Y-m') < $current; $i++, $d = $d->modify('+1 month')) {
            if (!in_array($d->format('Y-m'), $closed, true)) {
                $out[] = $d->format('Y-m');
            }
        }
        return $out;
    }

    /**
     * Cierra todos los meses ya terminados y no cerrados (perezoso, sin cron). Barato cuando no hay nada pendiente
     * (una consulta); con pendientes toma GET_LOCK y cada mes se cierra en su propia transacción.
     *
     * @return array{closed:list<string>, granted:int}
     */
    public static function closePendingMonths(?PDO $pdo = null, ?int $now = null): array
    {
        $pdo ??= db();
        $res = ['closed' => [], 'granted' => 0];
        if (self::pendingMonths($pdo, $now) === []) {
            return $res;
        }
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            return $res;
        }
        try {
            foreach (self::pendingMonths($pdo, $now) as $ym) {
                $n = self::closeMonth($pdo, $ym);
                if ($n >= 0) {
                    $res['closed'][] = $ym;
                    $res['granted'] += $n;
                }
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
        }
        return $res;
    }

    /** Cierra un mes: marca + premios en UNA transacción. Devuelve premios otorgados, o -1 si ya estaba cerrado. */
    public static function closeMonth(PDO $pdo, string $ym): int
    {
        $cfg = self::config();
        [$from, $to] = self::monthWindow($ym);
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO awards_closed_months (ym) VALUES (?)');
            $ins->execute([$ym]);
            if ($ins->rowCount() !== 1) {
                $pdo->rollBack();
                return -1;
            }
            $granted = 0;
            foreach (Ranking::rows($from, $to, (int) $cfg['top_n']) as $i => $r) {
                if ($r['total'] < (int) $cfg['min_month_cents']) {
                    break;   // ordenado por total: los siguientes tampoco llegan
                }
                $place = $i + 1;
                $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$r['user_id']]);
                $badge = $place <= 3 ? 'mecenas_' . $place : 'top_mes';
                if (self::grant($pdo, $r['user_id'], 'month:' . $ym . ':' . $place, 'month', (int) $cfg['month_coins'][$i], $badge, $ym)) {
                    $granted++;
                }
            }
            // Gancho: premios mensuales de creadores (solo meses desde su lanzamiento; misma transacción e idempotentes).
            if ($ym >= CreatorAwards::launchMonth()) {
                $granted += CreatorAwards::closeMonth($pdo, $ym);
            }
            $pdo->commit();
            return $granted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------- hitos ---

    /** Insignia del hito en la posición $index (0 = primero). */
    public static function milestoneBadge(int $index): string
    {
        return self::MILESTONE_BADGES[$index] ?? 'apoyo_hito';
    }

    /** Gancho de Payments::fulfill (misma transacción, tras marcar fulfilled_at). Nunca rompe el pago. */
    public static function onPaymentFulfilled(PDO $pdo, int $userId): void
    {
        $pdo->exec('SAVEPOINT awards_hook');
        try {
            self::evaluateMilestones($pdo, $userId);
            $pdo->exec('RELEASE SAVEPOINT awards_hook');
        } catch (Throwable $e) {
            error_log('Awards::onPaymentFulfilled: ' . $e->getMessage());
            $pdo->exec('ROLLBACK TO SAVEPOINT awards_hook');
        }
    }

    /**
     * Otorga los hitos de gasto acumulado que el usuario ya alcanzó y aún no tiene (una sola vez cada uno).
     * Dentro de una transacción abierta la reutiliza; si no, abre la suya. Devuelve cuántos otorgó.
     */
    public static function evaluateMilestones(PDO $pdo, int $userId): int
    {
        $cfg = self::config();
        $keys = static fn(array $due): array => array_map(static fn(array $m): string => 'milestone:' . $m['cents'], $due);
        $dueOf = static fn(int $total): array => array_filter($cfg['milestones'], static fn(array $m): bool => $m['cents'] <= $total);
        $own = !$pdo->inTransaction();
        if ($own) {   // camino perezoso: sin bloqueos si no hay nada pendiente
            $due = $dueOf(Ranking::totalFor($userId, ...self::ALL_TIME));
            if ($due === []) {
                return 0;
            }
            $st = $pdo->prepare("SELECT grant_key FROM award_grants WHERE user_id = ? AND kind = 'milestone'");
            $st->execute([$userId]);
            if (array_diff($keys($due), $st->fetchAll(PDO::FETCH_COLUMN)) === []) {
                return 0;
            }
            $pdo->beginTransaction();
        }
        $open = $own;
        try {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $n = 0;
            if ($lock->fetchColumn() !== false) {
                foreach ($dueOf(Ranking::totalFor($userId, ...self::ALL_TIME)) as $i => $m) {
                    if (self::grant($pdo, $userId, 'milestone:' . $m['cents'], 'milestone', $m['coins'], self::milestoneBadge($i), '')) {
                        $n++;
                    }
                }
            }
            if ($own) {
                $pdo->commit();
                $open = false;
            }
            return $n;
        } catch (Throwable $e) {
            if ($open) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Progreso hacia el siguiente hito.
     *
     * @return array{next:?array{cents:int,coins:int}, prev:int, pct:int, left:int}
     */
    public static function progress(int $cents): array
    {
        $prev = 0;
        foreach (self::config()['milestones'] as $m) {
            if ($cents < $m['cents']) {
                $span = $m['cents'] - $prev;
                return ['next' => $m, 'prev' => $prev, 'pct' => (int) max(0, min(100, floor(($cents - $prev) * 100 / $span))), 'left' => $m['cents'] - $cents];
            }
            $prev = $m['cents'];
        }
        return ['next' => null, 'prev' => $prev, 'pct' => 100, 'left' => 0];
    }

    // -------------------------------------------------------------- núcleo ---

    /**
     * Concesión idempotente: exige transacción abierta. true solo si es nueva (y entonces acredita monedas e insignia).
     */
    public static function grant(PDO $pdo, int $userId, string $grantKey, string $kind, int $coins, string $badge, string $period): bool
    {
        $ins = $pdo->prepare('INSERT IGNORE INTO award_grants (user_id, grant_key, kind, coins) VALUES (?, ?, ?, ?)');
        $ins->execute([$userId, $grantKey, $kind, $coins]);
        if ($ins->rowCount() !== 1) {
            return false;
        }
        if ($coins > 0) {
            Coins::credit($userId, $coins, 'award_' . $kind, $grantKey);
        }
        if ($badge !== '') {
            $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_key, period) VALUES (?, ?, ?)')->execute([$userId, $badge, $period]);
        }
        return true;
    }

    // ------------------------------------------------------------- lectura ---

    /**
     * Claves de insignia distintas por usuario (más recientes primero, máx. 4).
     *
     * @param list<int> $userIds
     * @return array<int,list<string>>
     */
    public static function badgeKeysFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $st = db()->prepare("SELECT user_id, badge_key FROM user_badges WHERE user_id IN ($in) ORDER BY id DESC");
        $st->execute($userIds);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $k = (string) $r['badge_key'];
            $uid = (int) $r['user_id'];
            if (isset(self::BADGES[$k]) && !in_array($k, $out[$uid] ?? [], true) && count($out[$uid] ?? []) < 4) {
                $out[$uid][] = $k;
            }
        }
        return $out;
    }

    /** Insignias del usuario con periodo. @return list<array{key:string, period:string}> */
    public static function userBadges(int $userId): array
    {
        $st = db()->prepare('SELECT badge_key, period FROM user_badges WHERE user_id = ? ORDER BY id DESC');
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (isset(self::BADGES[$r['badge_key']])) {
                $out[] = ['key' => (string) $r['badge_key'], 'period' => (string) $r['period']];
            }
        }
        return $out;
    }

    /** Hitos ya otorgados al usuario (monto en centavos). @return list<int> */
    public static function grantedMilestones(int $userId): array
    {
        $st = db()->prepare("SELECT grant_key FROM award_grants WHERE user_id = ? AND kind = 'milestone'");
        $st->execute([$userId]);
        return array_map(static fn($k): int => (int) substr((string) $k, 10), $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** HTML de una insignia (icono + nombre accesible). */
    public static function badgeImg(string $key, int $size = 24): string
    {
        $b = self::BADGES[$key] ?? null;
        if ($b === null) {
            return '';
        }
        return '<img src="' . e(url('assets/img/awards/' . $b[2] . '.svg')) . '" alt="' . e($b[0]) . '" title="' . e($b[0] . ': ' . $b[1]) . '" width="' . $size . '" height="' . $size . '" loading="lazy" class="shrink-0">';
    }
}
