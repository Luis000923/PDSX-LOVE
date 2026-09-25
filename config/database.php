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
const DB_SCHEMA_VERSION = 21;

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
        db_migrate_v10_tiers($pdo);  // ídem: el seed de planes también referencia template_unlocks_per_month
        db_migrate_v11_tiers($pdo);  // ídem: y html_uploads_per_month

        foreach (db_split_sql((string) file_get_contents(ROOT . '/database/schema.sql')) as $statement) {
            $pdo->exec($statement);
        }

        db_migrate_v4($pdo);   // idempotente: no depende de $from
        db_migrate_v5($pdo);
        db_migrate_v6($pdo);
        db_migrate_v7($pdo);
        db_migrate_v8($pdo, $from);
        db_migrate_v9($pdo, $from);
        db_migrate_v10($pdo);
        db_migrate_v11($pdo);
        db_migrate_v12($pdo);
        db_migrate_v13($pdo);
        db_migrate_v14($pdo);
        db_migrate_v15($pdo);
        db_migrate_v16($pdo);
        db_migrate_v17($pdo);
        db_migrate_v18($pdo);
        db_migrate_v19($pdo);
        db_migrate_v20($pdo);
        db_migrate_v21($pdo);   // Google OAuth: google_id, avatar_url, email_verified_at, last_login_at, has_password

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

/**
 * Plantillas "de membresía con cupo mensual": `templates.membership_unlocks = 1` marca una
 * plantilla premium cuyo acceso por membresía está limitado a `membership_tiers.template_unlocks_per_month`
 * plantillas DISTINTAS por mes calendario (hora de El Salvador; ver `template_unlocks` y
 * Access::templateUnlockUsage()); agotado el cupo (o sin membresía), se usa pagando `price_coins`.
 * Con `membership_unlocks = 0` (valor por defecto, plantillas existentes) el comportamiento no cambia:
 * la membresía la desbloquea sin límite, como hasta ahora.
 */
/**
 * Añade `membership_tiers.template_unlocks_per_month` ANTES de reaplicar schema.sql: su INSERT de
 * semilla ya referencia esta columna (mismo motivo que db_migrate_v7_tiers con `duration_months`).
 */
function db_migrate_v10_tiers(PDO $pdo): void
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $exists->execute(['membership_tiers']);
    if ((int) $exists->fetchColumn() > 0 && !db_column_exists($pdo, 'membership_tiers', 'template_unlocks_per_month')) {
        $pdo->exec('ALTER TABLE membership_tiers ADD COLUMN template_unlocks_per_month INT NOT NULL DEFAULT 0');
        // Cupo inicial de los 3 planes ya sembrados (ver database/schema.sql, que ya no puede
        // pisar este valor en actualizaciones futuras del INSERT ... ON DUPLICATE KEY).
        $pdo->exec("UPDATE membership_tiers SET template_unlocks_per_month = CASE slug
                        WHEN 'romantico' THEN 1 WHEN 'pareja' THEN 3 WHEN 'eterno' THEN 6 ELSE 0 END");
    }
}

function db_migrate_v10(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'templates', 'membership_unlocks')) {
        $pdo->exec('ALTER TABLE templates ADD COLUMN membership_unlocks TINYINT(1) NOT NULL DEFAULT 0');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS template_unlocks (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT      NOT NULL,
        template_id INT      NOT NULL,
        tier_id     INT      NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_tplunlocks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_tplunlocks_tpl  FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
        INDEX idx_tplunlocks_user_month (user_id, created_at),
        INDEX idx_tplunlocks_user_tpl (user_id, template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Añade `membership_tiers.html_uploads_per_month` ANTES de reaplicar schema.sql (su seed la referencia).
 * Cupo mensual de subidas de HTML propio por plan: Romántico 3, Pareja 6, Eterno 12. El plan gratuito
 * (sin fila en esta tabla) usa la constante Access::FREE_MONTHLY_HTML_UPLOADS.
 */
function db_migrate_v11_tiers(PDO $pdo): void
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $exists->execute(['membership_tiers']);
    if ((int) $exists->fetchColumn() > 0 && !db_column_exists($pdo, 'membership_tiers', 'html_uploads_per_month')) {
        $pdo->exec('ALTER TABLE membership_tiers ADD COLUMN html_uploads_per_month INT NOT NULL DEFAULT 0');
        $pdo->exec("UPDATE membership_tiers SET html_uploads_per_month = CASE slug
                        WHEN 'romantico' THEN 3 WHEN 'pareja' THEN 6 WHEN 'eterno' THEN 12 ELSE 0 END");
    }
}

/**
 * v11: fotos de usuarios por plantilla y HTML propio de los usuarios.
 *  - templates.image_spec: JSON con las fotos que pide la plantilla (ver TemplateImages).
 *  - site_images: fotos procesadas de cada página (los archivos viven en public/uploads/sites/{slug}/).
 *  - user_html_sites: metadatos del HTML propio de una página (el archivo vive FUERA del webroot, en storage/user_html/{slug}/).
 *  - html_uploads: libro de subidas de HTML propio (cupo mensual por plan); NO se borra al borrar la página.
 *  - Plantilla oculta 'html-propio' (kind = 'user'): la comparten todas las páginas de HTML propio.
 */
function db_migrate_v11(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'templates', 'image_spec')) {
        $pdo->exec('ALTER TABLE templates ADD COLUMN image_spec TEXT NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_images (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        site_id    INT          NOT NULL,
        slot       VARCHAR(40)  NOT NULL,
        file       VARCHAR(80)  NOT NULL,
        mime       VARCHAR(20)  NOT NULL,
        width      INT          NOT NULL,
        height     INT          NOT NULL,
        bytes      INT          NOT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_site_images_slot (site_id, slot),
        CONSTRAINT fk_site_images_site FOREIGN KEY (site_id) REFERENCES user_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_html_sites (
        site_id    INT          NOT NULL PRIMARY KEY,
        sha256     CHAR(64)     NOT NULL,
        bytes      INT          NOT NULL,
        has_assets TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_user_html_site FOREIGN KEY (site_id) REFERENCES user_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS html_uploads (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT          NOT NULL,
        site_id    INT          NULL,
        tier_id    INT          NULL,
        sha256     CHAR(64)     NOT NULL,
        bytes      INT          NOT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_html_uploads_user_month (user_id, created_at),
        CONSTRAINT fk_html_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_active)
                VALUES ('html-propio', 'HTML propio', 'user', 'especial', 'html-propio', 'Página con HTML propio subido por el usuario.', 1)");
    // Auto-reparación: esta fila SIEMPRE debe ser kind = 'user' y estar activa (view.php la une con is_active = 1).
    $pdo->exec("UPDATE templates SET kind = 'user', is_active = 1 WHERE slug = 'html-propio' AND (kind <> 'user' OR is_active <> 1)");
}

/**
 * v12: top de donadores, premios automáticos e insignias.
 *  - users.display_name / show_in_rankings: alias público opt-in (por defecto NADIE aparece con identidad).
 *  - idx_payments_rank (status, fulfilled_at, user_id): sirve al ranking mensual/histórico.
 *  - awards_closed_months, user_badges, award_grants: cierre de mes, insignias y libro de concesiones (UNIQUE = idempotencia).
 *  - settings.awards_launch_month: mes de lanzamiento (hora de El Salvador); no se premian meses anteriores.
 */
function db_migrate_v12(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'users', 'display_name')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN display_name VARCHAR(30) NULL');
    }
    if (!db_column_exists($pdo, 'users', 'show_in_rankings')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN show_in_rankings TINYINT(1) NOT NULL DEFAULT 0');
    }
    $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_rank'");
    if ($idx !== false && (int) $idx->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE payments ADD KEY idx_payments_rank (status, fulfilled_at, user_id)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS awards_closed_months (
        ym        CHAR(7)  NOT NULL PRIMARY KEY,
        closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_badges (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT         NOT NULL,
        badge_key  VARCHAR(40) NOT NULL,
        period     VARCHAR(7)  NOT NULL DEFAULT '',
        created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_badges (user_id, badge_key, period),
        CONSTRAINT fk_user_badges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS award_grants (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT         NOT NULL,
        grant_key  VARCHAR(80) NOT NULL,
        kind       VARCHAR(20) NOT NULL,
        coins      INT         NOT NULL DEFAULT 0,
        created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_award_grants (user_id, grant_key),
        KEY idx_award_grants_created (created_at),
        CONSTRAINT fk_award_grants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $launch = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m');
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('awards_launch_month', ?)")->execute([$launch]);
}

/**
 * v13: economía de creadores. Plantillas públicas de usuario (templates.kind = 'utpl') con revisión previa,
 * ledger de ganancias (template_earnings) y mejora temporal de plan (users.bonus_tier_*).
 */
function db_migrate_v13(PDO $pdo): void
{
    $cols = [
        'owner_user_id' => 'INT NULL',
        'review_status' => "VARCHAR(10) NOT NULL DEFAULT 'approved'",
        'review_note'   => 'VARCHAR(255) NULL',
        'credit_alias'  => 'VARCHAR(30) NULL',
        'submitted_at'  => 'DATETIME NULL',
        'reviewed_at'   => 'DATETIME NULL',
        'reviewed_by'   => 'INT NULL',
    ];
    foreach ($cols as $c => $def) {
        if (!db_column_exists($pdo, 'templates', $c)) {
            $pdo->exec("ALTER TABLE templates ADD COLUMN `$c` $def");
        }
    }
    $hasIdx = static function (string $table, string $name) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $name]);
        return (int) $st->fetchColumn() > 0;
    };
    $hasFk = static function (string $table, string $name) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $st->execute([$table, $name]);
        return (int) $st->fetchColumn() > 0;
    };
    if (!$hasIdx('templates', 'idx_templates_owner')) {
        $pdo->exec('ALTER TABLE templates ADD KEY idx_templates_owner (owner_user_id, review_status)');
    }
    if (!$hasIdx('templates', 'idx_templates_review')) {
        $pdo->exec('ALTER TABLE templates ADD KEY idx_templates_review (review_status, kind, is_active)');
    }
    if (!$hasFk('templates', 'fk_templates_owner')) {
        $pdo->exec('ALTER TABLE templates ADD CONSTRAINT fk_templates_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL');
    }
    if (!$hasFk('templates', 'fk_templates_reviewer')) {
        $pdo->exec('ALTER TABLE templates ADD CONSTRAINT fk_templates_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');
    }
    if (!db_column_exists($pdo, 'users', 'bonus_tier_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_tier_id INT NULL, ADD COLUMN bonus_tier_expires_at DATETIME NULL,
                    ADD CONSTRAINT fk_users_bonus_tier FOREIGN KEY (bonus_tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL');
    }
    // Alias único (sin distinguir mayúsculas/tildes: colación unicode_ci). Solo si no hay duplicados previos.
    if (!$hasIdx('users', 'uq_users_display_name')) {
        $dups = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT display_name FROM users WHERE display_name IS NOT NULL GROUP BY display_name HAVING COUNT(*) > 1) d')->fetchColumn();
        if ($dups === 0) {
            $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_display_name (display_name)');
        } else {
            error_log('db_migrate_v13: hay alias duplicados en users.display_name; se omite el índice único uq_users_display_name (resuélvelos y reinicia).');
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS template_earnings (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT         NOT NULL,
        creator_id  INT         NOT NULL,
        buyer_id    INT         NULL,
        site_id     INT         NULL,
        ref         VARCHAR(80) NOT NULL,
        kind        ENUM('coins','quota') NOT NULL,
        base_coins  INT         NOT NULL,
        share_coins INT         NOT NULL,
        status      ENUM('pending','paid') NOT NULL DEFAULT 'pending',
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at     DATETIME    NULL,
        UNIQUE KEY uq_earnings_ref (template_id, ref),
        KEY idx_earnings_creator (creator_id, status),
        KEY idx_earnings_template (template_id, created_at),
        CONSTRAINT fk_earnings_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
        CONSTRAINT fk_earnings_buyer    FOREIGN KEY (buyer_id)    REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $launch = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m');
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('creators_launch_month', ?)")->execute([$launch]);
}

/**
 * v14: referidos (código propio + quién invitó), check-in diario (última fecha reclamada) y cofres de aniversario.
 * `referral_rewards.referred_id` es UNIQUE: la comisión por primera compra solo puede existir una vez por referido.
 */
function db_migrate_v14(PDO $pdo): void
{
    $cols = [
        'referral_code'     => 'VARCHAR(12) NULL',
        'referred_by'       => 'INT NULL',
        'last_checkin_date' => 'DATE NULL',
    ];
    foreach ($cols as $c => $def) {
        if (!db_column_exists($pdo, 'users', $c)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$c` $def");
        }
    }
    $hasIdx = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = ?");
        $st->execute([$name]);
        return (int) $st->fetchColumn() > 0;
    };
    if (!$hasIdx('uq_users_referral_code')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_referral_code (referral_code)');
    }
    if (!$hasIdx('idx_users_referred_by')) {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_referred_by (referred_by)');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_referred_by'");
    $st->execute();
    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_rewards (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        referrer_id INT      NOT NULL,
        referred_id INT      NOT NULL,
        payment_id  INT      NULL,
        coins       INT      NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_referral_referred (referred_id),
        KEY idx_referral_referrer (referrer_id, id),
        CONSTRAINT fk_referral_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_referral_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS anniversary_chests (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT         NOT NULL,
        site_id     INT         NOT NULL,
        milestone   VARCHAR(8)  NOT NULL,
        coins       INT         NOT NULL,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        opened_at   DATETIME    NULL,
        UNIQUE KEY uq_chest_site_milestone (site_id, milestone),
        KEY idx_chest_user (user_id, opened_at),
        CONSTRAINT fk_chest_user FOREIGN KEY (user_id) REFERENCES users(id)      ON DELETE CASCADE,
        CONSTRAINT fk_chest_site FOREIGN KEY (site_id) REFERENCES user_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** v15: plantillas HTML adicionales para cumpleaños, aniversarios y otras ocasiones. */
function db_migrate_v15(PDO $pdo): void
{
    $templates = [
        ['cumpleanos-fiesta', 'Fiesta de cumpleaños', 'cumpleanos', 'cumpleanos-fiesta.html', 'Una sorpresa colorida para celebrar su día.', 0, 0],
        ['aniversario-constelacion', 'Constelación de aniversario', 'aniversario', 'aniversario-constelacion.html', 'Una historia de amor escrita entre estrellas.', 1, 5],
        ['declaracion-carta', 'Carta de declaración', 'declaracion', 'declaracion-carta.html', 'Una carta elegante para decir lo que sientes.', 0, 0],
        ['amistad-infinita', 'Amistad infinita', 'especial', 'amistad-infinita.html', 'Un homenaje alegre para tu mejor amigo o amiga.', 0, 0],
        ['graduacion-orgullo', 'Orgullo por tu graduación', 'especial', 'graduacion-orgullo.html', 'Celebra una meta cumplida y el próximo capítulo.', 0, 0],
        ['gracias-siempre', 'Gracias siempre', 'especial', 'gracias-siempre.html', 'Una nota cálida para agradecer a alguien especial.', 0, 0],
        ['navidad-juntos', 'Navidad juntos', 'especial', 'navidad-juntos.html', 'Un saludo navideño lleno de cariño.', 0, 0],
        ['distancia-contigo', 'A pesar de la distancia', 'especial', 'distancia-contigo.html', 'Un mensaje para mantener cerca a quien está lejos.', 0, 0],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v16: plantillas HTML para fechas familiares, disculpas, bodas y logros. */
function db_migrate_v16(PDO $pdo): void
{
    $templates = [
        ['san-valentin-luz', 'San Valentín a la luz', 'romantico', 'san-valentin-luz.html', 'Una dedicatoria luminosa para el amor de tu vida.', 1, 5],
        ['mama-mi-heroina', 'Mamá, mi heroína', 'especial', 'mama-mi-heroina.html', 'Un homenaje tierno para mamá.', 0, 0],
        ['papa-mi-guia', 'Papá, mi guía', 'especial', 'papa-mi-guia.html', 'Un reconocimiento especial para papá.', 0, 0],
        ['perdon-nuevo-comienzo', 'Un nuevo comienzo', 'declaracion', 'perdon-nuevo-comienzo.html', 'Una forma sincera de pedir perdón.', 0, 0],
        ['bienvenido-bebe', 'Bienvenido, bebé', 'especial', 'bienvenido-bebe.html', 'Una bienvenida dulce para una nueva vida.', 0, 0],
        ['boda-para-siempre', 'Boda para siempre', 'aniversario', 'boda-para-siempre.html', 'Una promesa elegante para celebrar el matrimonio.', 1, 5],
        ['mi-mejor-amigo', 'Mi mejor amigo', 'especial', 'mi-mejor-amigo.html', 'Una dedicatoria divertida para tu amistad.', 0, 0],
        ['logro-brillante', 'Logro brillante', 'especial', 'logro-brillante.html', 'Celebra una meta alcanzada con orgullo.', 0, 0],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v17: colección minimalista adicional para amor, familia, amistad y celebraciones. */
function db_migrate_v17(PDO $pdo): void
{
    $templates = [
        ['amor-editorial', 'Amor editorial', 'romantico', 'amor-editorial.html', 'Una dedicatoria minimalista con estilo de revista.', 1, 5],
        ['carta-aurora', 'Carta de buenos días', 'romantico', 'carta-aurora.html', 'Una nota luminosa para comenzar el día.', 0, 0],
        ['aniversario-linea', 'Aniversario en línea', 'aniversario', 'aniversario-linea.html', 'Una celebración sobria de la historia compartida.', 0, 0],
        ['promesa-sencilla', 'Promesa sencilla', 'declaracion', 'promesa-sencilla.html', 'Un mensaje íntimo para elegir a alguien cada día.', 1, 5],
        ['feliz-cumpleanos', 'Cumpleaños esencial', 'cumpleanos', 'feliz-cumpleanos.html', 'Una felicitación limpia y alegre.', 0, 0],
        ['gracias-minimal', 'Gracias minimal', 'especial', 'gracias-minimal.html', 'Una nota breve para decir gracias con elegancia.', 0, 0],
        ['te-extrano', 'Te extraño', 'romantico', 'te-extrano.html', 'Un mensaje nocturno para acortar la distancia.', 0, 0],
        ['buenos-dias-amor', 'Buenos días, amor', 'romantico', 'buenos-dias-amor.html', 'Una sorpresa cálida para empezar la mañana.', 0, 0],
        ['buenas-noches-cielo', 'Buenas noches, cielo', 'romantico', 'buenas-noches-cielo.html', 'Una despedida dulce antes de dormir.', 0, 0],
        ['felicidades-logro', 'Felicidades por tu logro', 'especial', 'felicidades-logro.html', 'Un reconocimiento elegante para una meta alcanzada.', 1, 5],
        ['graduacion-elegante', 'Graduación elegante', 'especial', 'graduacion-elegante.html', 'Una felicitación editorial para cerrar una etapa.', 0, 0],
        ['nueva-casa', 'Nueva casa', 'especial', 'nueva-casa.html', 'Un deseo cálido para un nuevo hogar.', 0, 0],
        ['nuevo-trabajo', 'Nuevo trabajo', 'especial', 'nuevo-trabajo.html', 'Mucho éxito en el próximo capítulo profesional.', 0, 0],
        ['dia-especial', 'Un día especial', 'especial', 'dia-especial.html', 'Una sorpresa hermosa porque sí.', 0, 0],
        ['mama-calma', 'Mamá, gracias por tanto', 'especial', 'mama-calma.html', 'Una dedicatoria serena y amorosa para mamá.', 0, 0],
        ['papa-clasico', 'Papá, mi guía', 'especial', 'papa-clasico.html', 'Un mensaje clásico para agradecer a papá.', 0, 0],
        ['amistad-coral', 'Amistad de la buena', 'especial', 'amistad-coral.html', 'Una dedicatoria divertida para una amistad especial.', 0, 0],
        ['disculpa-blanca', 'Disculpa blanca', 'declaracion', 'disculpa-blanca.html', 'Una disculpa honesta y tranquila.', 0, 0],
        ['boda-marfil', 'Boda marfil', 'aniversario', 'boda-marfil.html', 'Una promesa elegante para una vida juntos.', 1, 5],
        ['mascota-companera', 'Mascota compañera', 'especial', 'mascota-companera.html', 'Una dedicatoria tierna para tu compañero de aventuras.', 0, 0],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v18: cuarenta templates minimalistas para amor, familia, amistad y ocasiones diarias. */
function db_migrate_v18(PDO $pdo): void
{
    $templates = [
        ['amor-quieto', 'Amor quieto', 'romantico', 'amor-quieto.html', 'Una dedicatoria serena y elegante.', 1, 5],
        ['latido-rosa', 'Latido rosa', 'romantico', 'latido-rosa.html', 'Una carta para la persona favorita.', 0, 0],
        ['rosa-secreta', 'Rosa secreta', 'romantico', 'rosa-secreta.html', 'Una dedicatoria tierna y discreta.', 0, 0],
        ['carta-lino', 'Carta de lino', 'romantico', 'carta-lino.html', 'Una carta cálida de estilo editorial.', 0, 0],
        ['siempre-contigo', 'Siempre contigo', 'romantico', 'siempre-contigo.html', 'Un mensaje para elegir a alguien siempre.', 1, 5],
        ['mi-lugar-seguro', 'Mi lugar seguro', 'romantico', 'mi-lugar-seguro.html', 'Una dedicatoria para quien da tranquilidad.', 0, 0],
        ['mi-persona', 'Mi persona', 'romantico', 'mi-persona.html', 'Una nota para alguien extraordinario.', 0, 0],
        ['nuestro-capitulo', 'Nuestro capítulo', 'aniversario', 'nuestro-capitulo.html', 'Una página más de la historia compartida.', 0, 0],
        ['amores-de-domingo', 'Amores de domingo', 'romantico', 'amores-de-domingo.html', 'Una dedicatoria tranquila para compartir.', 0, 0],
        ['pequena-sorpresa', 'Pequeña sorpresa', 'especial', 'pequena-sorpresa.html', 'Un detalle bonito porque sí.', 0, 0],
        ['cafe-y-carino', 'Café y cariño', 'romantico', 'cafe-y-carino.html', 'Una pausa cálida para alguien especial.', 0, 0],
        ['papel-dorado', 'Papel dorado', 'especial', 'papel-dorado.html', 'Una dedicatoria sobria para una ocasión especial.', 1, 5],
        ['manana-mejor', 'Mañana será mejor', 'especial', 'manana-mejor.html', 'Un mensaje de ánimo y esperanza.', 0, 0],
        ['tu-sonrisa', 'Tu sonrisa', 'romantico', 'tu-sonrisa.html', 'Una razón para sonreír hoy.', 0, 0],
        ['abrazo-a-distancia', 'Abrazo a distancia', 'especial', 'abrazo-a-distancia.html', 'Un abrazo para quien está lejos.', 0, 0],
        ['eres-increible', 'Eres increíble', 'especial', 'eres-increible.html', 'Un reconocimiento especial y positivo.', 0, 0],
        ['mi-orgullo', 'Mi orgullo', 'especial', 'mi-orgullo.html', 'Un mensaje de admiración por sus logros.', 0, 0],
        ['primer-paso', 'Primer paso', 'especial', 'primer-paso.html', 'Un impulso para comenzar algo grande.', 0, 0],
        ['te-elijo', 'Te elijo', 'declaracion', 'te-elijo.html', 'Una promesa sencilla y romántica.', 1, 5],
        ['familia-siempre', 'Familia siempre', 'especial', 'familia-siempre.html', 'Una dedicatoria para celebrar el hogar.', 0, 0],
        ['mi-hermana', 'Para mi hermana', 'especial', 'mi-hermana.html', 'Una nota para una cómplice de vida.', 0, 0],
        ['mi-hermano', 'Para mi hermano', 'especial', 'mi-hermano.html', 'Un mensaje para un compañero de siempre.', 0, 0],
        ['feliz-dia', 'Feliz día', 'especial', 'feliz-dia.html', 'Una sorpresa sencilla para alegrar el día.', 0, 0],
        ['mes-de-amor', 'Mes de amor', 'aniversario', 'mes-de-amor.html', 'Una celebración íntima de la relación.', 0, 0],
        ['te-admiro', 'Te admiro', 'especial', 'te-admiro.html', 'Un reconocimiento sincero.', 0, 0],
        ['un-mensaje-bonito', 'Un mensaje bonito', 'especial', 'un-mensaje-bonito.html', 'Una dedicatoria para alegrar a alguien.', 0, 0],
        ['celebrar-te', 'Celebrarte', 'especial', 'celebrar-te.html', 'Una felicitación para una persona especial.', 0, 0],
        ['eres-mi-hogar', 'Eres mi hogar', 'romantico', 'eres-mi-hogar.html', 'Una declaración de amor y pertenencia.', 1, 5],
        ['por-siempre-juntos', 'Por siempre juntos', 'aniversario', 'por-siempre-juntos.html', 'Una promesa para compartir la vida.', 1, 5],
        ['mi-mejor-dia', 'Mi mejor día', 'especial', 'mi-mejor-dia.html', 'Una memoria para guardar con cariño.', 0, 0],
        ['gracias-por-estar', 'Gracias por estar', 'especial', 'gracias-por-estar.html', 'Una nota de gratitud profunda.', 0, 0],
        ['mi-estrella', 'Mi estrella', 'romantico', 'mi-estrella.html', 'Un mensaje para alguien que siempre brilla.', 0, 0],
        ['un-beso', 'Un beso', 'romantico', 'un-beso.html', 'Un detalle pequeño lleno de amor.', 0, 0],
        ['contigo-todo', 'Contigo todo', 'romantico', 'contigo-todo.html', 'Una promesa cotidiana para compartir.', 0, 0],
        ['brindis-por-ti', 'Un brindis por ti', 'especial', 'brindis-por-ti.html', 'Una celebración elegante y cálida.', 0, 0],
        ['pequenos-momentos', 'Pequeños momentos', 'romantico', 'pequenos-momentos.html', 'Una dedicatoria para lo sencillo.', 0, 0],
        ['te-quiero-cerca', 'Te quiero cerca', 'romantico', 'te-quiero-cerca.html', 'Una nota para mantener cerca a alguien.', 0, 0],
        ['eres-mi-paz', 'Eres mi paz', 'romantico', 'eres-mi-paz.html', 'Una dedicatoria para quien tranquiliza.', 1, 5],
        ['nuestro-futuro', 'Nuestro futuro', 'aniversario', 'nuestro-futuro.html', 'Un mensaje para mirar adelante juntos.', 0, 0],
        ['sonrie-hoy', 'Sonríe hoy', 'especial', 'sonrie-hoy.html', 'Un recordatorio amable para alegrar el día.', 0, 0],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v19: dieciocho templates para apoyo, familia, amistad y celebraciones. */
function db_migrate_v19(PDO $pdo): void
{
    $templates = [
        ['amor-en-detalle', 'Amor en detalle', 'romantico', 'amor-en-detalle.html', 'Una dedicatoria para los pequeños detalles.', 0, 0],
        ['carta-azul', 'Carta azul', 'romantico', 'carta-azul.html', 'Una carta tranquila para alguien especial.', 0, 0],
        ['carta-roja', 'Carta roja', 'romantico', 'carta-roja.html', 'Una carta intensa y romántica.', 1, 5],
        ['domingo-contigo', 'Domingo contigo', 'romantico', 'domingo-contigo.html', 'Una dedicatoria para disfrutar sin prisa.', 0, 0],
        ['buenas-noticias', 'Buenas noticias', 'especial', 'buenas-noticias.html', 'Una sorpresa para celebrar una buena noticia.', 0, 0],
        ['mucho-animo', 'Mucho ánimo', 'especial', 'mucho-animo.html', 'Un mensaje para acompañar un día difícil.', 0, 0],
        ['nuevo-comienzo', 'Nuevo comienzo', 'especial', 'nuevo-comienzo.html', 'Una nota para abrir una nueva etapa.', 0, 0],
        ['gracias-amiga', 'Gracias, amiga', 'especial', 'gracias-amiga.html', 'Una dedicatoria para una amiga especial.', 0, 0],
        ['gracias-amigo', 'Gracias, amigo', 'especial', 'gracias-amigo.html', 'Una dedicatoria para un amigo especial.', 0, 0],
        ['cumpleanos-elegante', 'Cumpleaños elegante', 'cumpleanos', 'cumpleanos-elegante.html', 'Una felicitación sobria y hermosa.', 1, 5],
        ['aniversario-dorado', 'Aniversario dorado', 'aniversario', 'aniversario-dorado.html', 'Una celebración de amor duradero.', 1, 5],
        ['te-apoyo', 'Te apoyo', 'especial', 'te-apoyo.html', 'Una promesa de acompañamiento.', 0, 0],
        ['te-escucho', 'Te escucho', 'especial', 'te-escucho.html', 'Un mensaje de presencia y empatía.', 0, 0],
        ['eres-mi-fortaleza', 'Eres mi fortaleza', 'romantico', 'eres-mi-fortaleza.html', 'Una dedicatoria para quien sostiene.', 0, 0],
        ['un-dia-inolvidable', 'Un día inolvidable', 'especial', 'un-dia-inolvidable.html', 'Una memoria especial para guardar.', 0, 0],
        ['mi-complice', 'Mi cómplice', 'especial', 'mi-complice.html', 'Una dedicatoria para tu persona de confianza.', 0, 0],
        ['familia-corazon', 'Familia de corazón', 'especial', 'familia-corazon.html', 'Un mensaje para alguien que es familia.', 0, 0],
        ['felicidades-siempre', 'Felicidades siempre', 'especial', 'felicidades-siempre.html', 'Una felicitación para cualquier logro.', 0, 0],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v20: registrar los tres templates HTML históricos que ya existían en el repositorio. */
function db_migrate_v20(PDO $pdo): void
{
    $templates = [
        ['historia-numeros', 'Historia en números', 'especial', 'historia-numeros.html', 'Un contador detallado para celebrar la historia compartida.', 1, 5],
        ['sorpresa-cumple', 'Sorpresa de cumpleaños', 'cumpleanos', 'sorpresa-cumple.html', 'Una sorpresa interactiva para celebrar un cumpleaños.', 1, 5],
        ['quieres-ser-mi-novia', '¿Quieres ser mi novia?', 'declaracion', 'quieres-ser-mi-novia.html', 'Una propuesta romántica e interactiva.', 1, 5],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO templates (slug, name, kind, category, file, description, is_premium, price_coins, is_active) VALUES (?, ?, \'html\', ?, ?, ?, ?, ?, 1)');
    foreach ($templates as $template) {
        $st->execute($template);
    }
}

/** v21: acceso con Google (OAuth 2.0). Identificador de Google, foto, verificación del correo,
 *  último acceso y la marca de si la cuenta tiene contraseña propia (las de Google no). */
function db_migrate_v21(PDO $pdo): void
{
    $cols = [
        // El `sub` de Google identifica la cuenta; el correo puede cambiar en Google.
        'google_id'         => 'VARCHAR(128) NULL',
        'avatar_url'        => 'VARCHAR(512) NULL',
        'email_verified_at' => 'DATETIME NULL',
        'last_login_at'     => 'DATETIME NULL',
        'has_password'      => 'TINYINT(1)   NOT NULL DEFAULT 1',
    ];
    foreach ($cols as $col => $def) {
        if (!db_column_exists($pdo, 'users', $col)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
        }
    }
    // Una misma cuenta de Google no puede vincularse a dos usuarios distintos.
    $hasIdx = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute(['users', $name]);
        return (int) $st->fetchColumn() > 0;
    };
    if (!$hasIdx('uq_users_google_id')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_google_id (google_id)');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'users' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_users_has_password'");
    $st->execute();
    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD CONSTRAINT chk_users_has_password CHECK (has_password IN (0, 1))');
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
    // Quitar comentarios completos e inline, conservando los saltos de línea para que
    // las sentencias SQL no se unan ni cambien el contexto de los comentarios.
    $sql = (string) preg_replace('/--[^\r\n]*/', '', $sql);
    return array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        static fn(string $s): bool => $s !== ''
    ));
}
