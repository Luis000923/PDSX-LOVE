<?php
declare(strict_types=1);

/**
 * Conexión PDO a MySQL 8.0+ (singleton perezoso).
 * Si la base aún no tiene esquema, aplica database/schema.sql automáticamente;
 * si tiene uno de una versión anterior, aplica las migraciones pendientes.
 *
 * Variables de entorno (sin valores por defecto salvo el puerto): DB_HOST, DB_PORT (3306),
 * DB_NAME, DB_USER, DB_PASSWORD.
 */

/** Versión de esquema esperada por el código (tabla `schema_version`). */
const DB_SCHEMA_VERSION = 9;

/** Nombre del bloqueo consultivo que serializa las migraciones entre procesos. */
const DB_MIGRATION_LOCK = 'lovepages_schema_migration';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $missing = array_filter(
        ['DB_HOST', 'DB_NAME', 'DB_USER'],
        static fn(string $k): bool => (string) env($k) === ''
    );
    if ($missing !== []) {
        // Falla ruidosa y sin credenciales en el mensaje: mejor un 500 claro que una conexión a medias.
        throw new RuntimeException('Faltan variables de entorno de base de datos: ' . implode(', ', $missing));
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        env('DB_HOST'),
        (int) env('DB_PORT', '3306'),
        env('DB_NAME')
    );

    $conn = new PDO($dsn, (string) env('DB_USER'), (string) env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // sentencias preparadas reales (nativas del servidor)
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    // Toda la app guarda y compara fechas en UTC (DEFAULT CURRENT_TIMESTAMP, UTC_TIMESTAMP()...),
    // igual que el datetime('now') de SQLite. Sin esto dependería de la zona del servidor MySQL.
    $conn->exec("SET time_zone = '+00:00'");

    if (db_schema_version($conn) < DB_SCHEMA_VERSION) {
        db_migrate($conn);
    }
    return $pdo = $conn;
}

/** Versión de esquema instalada (0 = base vacía / sin tabla de control). */
function db_schema_version(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {   // ER_NO_SUCH_TABLE: instalación nueva
            return 0;
        }
        throw $e;
    }
}

/**
 * Lleva la base al esquema actual. Idempotente y seguro sobre bases ya pobladas.
 *
 * MySQL confirma implícitamente cada sentencia DDL (CREATE/ALTER no son transaccionales),
 * así que no se puede envolver el esquema en una transacción como en SQLite. Se consigue lo
 * mismo de otra forma:
 *   1. GET_LOCK serializa las migraciones: dos contenedores arrancando a la vez no se pisan.
 *   2. Cada paso es idempotente (CREATE ... IF NOT EXISTS / INSERT IGNORE), de modo que un
 *      fallo a medias se reintenta sin daño en el siguiente arranque.
 *   3. La versión se anota en `schema_version` SOLO al final: si algo falla, sigue "pendiente".
 *
 * Para añadir un cambio (v4...): súbelo a DB_SCHEMA_VERSION y añade un bloque
 * `if ($from < 4) { ... }` antes de anotar la versión; los ALTER TABLE que no puedan ser
 * idempotentes deben ir ahí, protegidos por ese `$from`.
 */
function db_migrate(PDO $pdo): void
{
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute([DB_MIGRATION_LOCK]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudo obtener el bloqueo de migración (otro proceso lo retiene).');
    }

    try {
        // Otro proceso pudo terminar mientras esperábamos el bloqueo.
        $from = db_schema_version($pdo);
        if ($from >= DB_SCHEMA_VERSION) {
            return;
        }

        db_migrate_v7_tiers($pdo);
        db_migrate_v6_tiers($pdo);   // antes del esquema: su seed de planes usa las columnas nuevas y los slugs nuevos

        foreach (db_split_sql((string) file_get_contents(ROOT . '/database/schema.sql')) as $statement) {
            $pdo->exec($statement);
        }

        db_migrate_v4($pdo);   // idempotente: no depende de $from
        db_migrate_v5($pdo);
        db_migrate_v6($pdo);
        db_migrate_v7($pdo);
        db_migrate_v8($pdo, $from);
        db_migrate_v9($pdo, $from);

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (
            version    INT      NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // El primer usuario registrado es el administrador principal. Si hay usuarios pero ningún
        // administrador (datos importados de antes del panel), se promueve al más antiguo.
        // Se lee el id aparte: MySQL no deja hacer UPDATE con una subconsulta sobre la misma tabla.
        if ((int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() === 0) {
            $oldest = $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
            if ($oldest !== null && $oldest !== false) {
                $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$oldest]);
            }
        }

        $pdo->prepare('INSERT IGNORE INTO schema_version (version) VALUES (?)')->execute([DB_SCHEMA_VERSION]);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([DB_MIGRATION_LOCK]);
    }
}

/**
 * v4: precio por plantilla en USD y pagos ligados a una plantilla. Bases anteriores tienen
 * `price_cop` y `payments` sin template_id; cada ALTER se protege con una comprobación en
 * information_schema para que un reintento a medias (o una base nueva) no falle.
 */
function db_migrate_v4(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'templates', 'price_usd')) {
        $pdo->exec('ALTER TABLE templates ADD COLUMN price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER description');
    }
    if (db_column_exists($pdo, 'templates', 'price_cop')) {
        $pdo->exec('ALTER TABLE templates DROP COLUMN price_cop');   // era solo de referencia, en pesos
    }
    if (!db_column_exists($pdo, 'payments', 'template_id')) {
        $pdo->exec('ALTER TABLE payments ADD COLUMN template_id INT NULL AFTER link_id,
                    ADD KEY idx_payments_template (template_id),
                    ADD CONSTRAINT fk_payments_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL');
    }
}

/**
 * v5: niveles de membresía, plantillas PHP y categorías. La tabla membership_tiers y sus filas
 * las crea schema.sql; aquí se añaden las columnas a bases anteriores y se conserva a los
 * Premium previos como Eterno (antes Oro) (el único nivel que iguala lo que ya tenían).
 */
function db_migrate_v5(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'users', 'membership_tier_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN membership_tier_id INT NULL AFTER is_admin,
                    ADD KEY idx_users_tier (membership_tier_id),
                    ADD CONSTRAINT fk_users_tier FOREIGN KEY (membership_tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL');
    }
    if (!db_column_exists($pdo, 'payments', 'tier_id')) {
        $pdo->exec('ALTER TABLE payments ADD COLUMN tier_id INT NULL AFTER template_id,
                    ADD KEY idx_payments_tier (tier_id),
                    ADD CONSTRAINT fk_payments_tier FOREIGN KEY (tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL');
    }
    if (!db_column_exists($pdo, 'templates', 'kind')) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'html' AFTER name");
    }
    if (!db_column_exists($pdo, 'templates', 'category')) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'romantico' AFTER kind");
    }
    $pdo->exec("UPDATE users SET membership_tier_id = (SELECT id FROM (SELECT id FROM membership_tiers WHERE slug = 'eterno') o)
                 WHERE is_premium = 1 AND membership_tier_id IS NULL");
}

/**
 * v6 (paso previo): economía de monedas en planes y plantillas. En bases con `membership_tiers` añade sus
 * columnas nuevas y renombra los planes (bronce→romantico, plata→pareja, oro→eterno) conservando
 * los ids, así los usuarios mantienen su plan. Se ejecuta ANTES de schema.sql porque el seed de
 * planes hace upsert por slug: sin el renombre crearía tres filas duplicadas.
 */
function db_migrate_v6_tiers(PDO $pdo): void
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $exists->execute(['templates']);
    if ((int) $exists->fetchColumn() > 0 && !db_column_exists($pdo, 'templates', 'price_coins')) {
        // El seed de plantillas de schema.sql ya escribe price_coins: la columna debe existir antes.
        $pdo->exec('ALTER TABLE templates ADD COLUMN price_coins INT NOT NULL DEFAULT 0 AFTER price_usd');
    }
    $exists->execute(['membership_tiers']);
    if ((int) $exists->fetchColumn() === 0) {
        return;   // instalación nueva: lo crea schema.sql
    }
    foreach ([
        'site_days'       => 'INT NOT NULL DEFAULT 3',
        'bonus_coins'     => 'INT NOT NULL DEFAULT 0',
        'topup_bonus_pct' => 'INT NOT NULL DEFAULT 0',
    ] as $col => $def) {
        if (!db_column_exists($pdo, 'membership_tiers', $col)) {
            $pdo->exec("ALTER TABLE membership_tiers ADD COLUMN $col $def");
        }
    }
    $rename = $pdo->prepare('UPDATE membership_tiers SET slug = ? WHERE slug = ?
                              AND NOT EXISTS (SELECT 1 FROM (SELECT slug FROM membership_tiers) x WHERE x.slug = ?)');
    foreach (['bronce' => 'romantico', 'plata' => 'pareja', 'oro' => 'eterno'] as $old => $new) {
        $rename->execute([$new, $old, $new]);
    }
}

/**
 * v6: monedas virtuales, caducidad de páginas y recargas. Columnas nuevas en users, templates,
 * user_sites y payments (cada ALTER protegido para poder reintentarse).
 */
function db_migrate_v6(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'users', 'coins')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN coins INT NOT NULL DEFAULT 0 AFTER membership_tier_id,
                    ADD CONSTRAINT chk_users_coins CHECK (coins >= 0)');
    }
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'templates' AND CONSTRAINT_NAME = 'chk_templates_price_coins'");
    if ($chk !== false && (int) $chk->fetchColumn() === 0) {   // la columna pudo crearla el paso previo, sin su CHECK
        $pdo->exec('ALTER TABLE templates ADD CONSTRAINT chk_templates_price_coins CHECK (price_coins >= 0)');
    }
    if (!db_column_exists($pdo, 'user_sites', 'expires_at')) {
        // Las páginas existentes quedan con NULL = sin caducidad: no se les quita nada retroactivamente.
        $pdo->exec('ALTER TABLE user_sites ADD COLUMN expires_at DATETIME NULL AFTER created_at, ADD KEY idx_sites_expires (expires_at)');
    }
    if (!db_column_exists($pdo, 'payments', 'coins')) {
        $pdo->exec('ALTER TABLE payments ADD COLUMN coins INT NULL AFTER promo_code');
    }
}

/**
 * v7 (paso previo, antes del seed de planes): duración en meses de cada plan. Solo si la tabla ya existe.
 */
function db_migrate_v7_tiers(PDO $pdo): void
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $exists->execute(['membership_tiers']);
    if ((int) $exists->fetchColumn() > 0 && !db_column_exists($pdo, 'membership_tiers', 'duration_months')) {
        $pdo->exec('ALTER TABLE membership_tiers ADD COLUMN duration_months INT NOT NULL DEFAULT 1,
                    ADD CONSTRAINT chk_tiers_duration CHECK (duration_months >= 1)');
    }
}

/**
 * v7: membresías con vencimiento. NULL = sin vencimiento: las cuentas con plan anteriores no caducan.
 */
function db_migrate_v7(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'users', 'membership_expires_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN membership_expires_at DATETIME NULL AFTER membership_tier_id');
    }
}

/**
 * v8: registro de creaciones/renovaciones (cuota mensual del plan gratuito). La tabla la crea schema.sql;
 * el backfill (una fila 'create' por página existente, tier NULL) solo corre al pasar de una versión < 8
 * y solo para usuarios sin filas todavía, así que reejecutarlo no duplica.
 */
function db_migrate_v8(PDO $pdo, int $from): void
{
    if ($from >= 8) {
        return;   // ya migrada
    }
    $pdo->exec("INSERT INTO site_creations (user_id, tier_id, kind, created_at)
                SELECT s.user_id, NULL, 'create', s.created_at FROM user_sites s
                 WHERE NOT EXISTS (SELECT 1 FROM site_creations c WHERE c.user_id = s.user_id)");
}

/**
 * v9: pagos con método/aprobación manual y cupones ampliados (hasta 100 %, alcance, un canje por usuario).
 * Columnas y CHECK se añaden solo si faltan (reintento seguro); promo_redemptions la crea schema.sql.
 * El backfill (pagos APPROVED previos ya cumplidos; canjes históricos) solo corre al venir de < 9.
 */
function db_migrate_v9(PDO $pdo, int $from): void
{
    $cols = [
        ['users',    'is_suspended', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['users',    'suspended_reason', 'VARCHAR(200) NULL'],
        ['users',    'suspended_at', 'DATETIME NULL'],
        ['payments', 'method',      "VARCHAR(12) NOT NULL DEFAULT 'WOMPI'"],
        ['payments', 'approved_by', 'INT NULL'],
        ['payments', 'admin_note',  'VARCHAR(255) NULL'],
        ['payments', 'fulfilled_at', 'DATETIME NULL'],
        ['promos',   'scope',       "VARCHAR(12) NOT NULL DEFAULT 'all'"],
        ['promos',   'tier_id',     'INT NULL'],
        ['promos',   'note',        'VARCHAR(120) NULL'],
        ['promos',   'created_by',  'INT NULL'],
    ];
    foreach ($cols as [$table, $col, $def]) {
        if (!db_column_exists($pdo, $table, $col)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
        }
    }

    $has = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'promos' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = ?");
        $st->execute([$name]);
        return (int) $st->fetchColumn() > 0;
    };
    if ($has('chk_promos_discount')) {
        $pdo->exec('ALTER TABLE promos DROP CHECK chk_promos_discount');
    }
    if (!$has('chk_promos_pct')) {
        $pdo->exec('ALTER TABLE promos ADD CONSTRAINT chk_promos_pct CHECK (discount_percent BETWEEN 1 AND 100)');
    }

    $ix = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_status_created'");
    $ix->execute();
    if ((int) $ix->fetchColumn() === 0) {
        $pdo->exec('CREATE INDEX idx_payments_status_created ON payments (status, created_at)');
    }

    if ($from < 9) {
        $pdo->exec("UPDATE payments SET fulfilled_at = updated_at WHERE status = 'APPROVED' AND fulfilled_at IS NULL");
        $pdo->exec("INSERT IGNORE INTO promo_redemptions (promo_id, user_id, payment_id, created_at)
                    SELECT pr.id, p.user_id, MIN(p.id), MIN(p.updated_at) FROM payments p
                      JOIN promos pr ON pr.code = p.promo_code
                     WHERE p.status = 'APPROVED' GROUP BY pr.id, p.user_id");
    }
}

/** ¿Existe la columna en la base actual? (para ALTER TABLE idempotentes) */
function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Divide un script SQL en sentencias sueltas. Se ejecutan una a una porque, con las
 * preparadas nativas, un error en la 2.ª sentencia de un lote multi-statement pasaría
 * desapercibido. Válido para database/schema.sql: sus comentarios son de línea (`-- `)
 * y ningún literal contiene `;` ni `--`. Si eso cambiara, hay que ampliar este divisor.
 *
 * @return list<string>
 */
function db_split_sql(string $sql): array
{
    $sql = (string) preg_replace('/--[^\n]*/', '', $sql);
    return array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        static fn(string $s): bool => $s !== ''
    ));
}
