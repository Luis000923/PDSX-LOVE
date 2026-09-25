-- LovePages: esquema (MySQL 8.0+ / InnoDB). Idempotente: se puede re-ejecutar sin daño.
--
-- Convenciones:
--   * Todas las tablas: ENGINE=InnoDB, utf8mb4 / utf8mb4_unicode_ci (colación
--     ACENTO- y MAYÚSCULA-insensible: sustituye al COLLATE NOCASE de SQLite en
--     `users.email` y `promos.code`).
--   * Booleanos: TINYINT(1) con CHECK (0,1) — MySQL 8.0.16+ los hace cumplir.
--   * Marcas de tiempo: DATETIME con DEFAULT CURRENT_TIMESTAMP. La conexión fija
--     time_zone='+00:00' (config/database.php), así que SIEMPRE se guardan en UTC,
--     igual que hacía datetime('now') en SQLite.
--   * MySQL no tiene CREATE INDEX IF NOT EXISTS: los índices se declaran DENTRO de
--     cada CREATE TABLE IF NOT EXISTS, que es lo que mantiene el archivo re-ejecutable.
--
-- Las columnas añadidas después de la v1 se aplican a bases existentes en db_migrate()
-- (config/database.php), que usa la tabla `schema_version` como marca de versión.

-- Niveles de membresía (pago único). Beneficios y precio los lee siempre el servidor.
CREATE TABLE IF NOT EXISTS membership_tiers (
    id                    INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug                  VARCHAR(20)   NOT NULL,
    name                  VARCHAR(40)   NOT NULL,
    price_usd             DECIMAL(10,2) NOT NULL,
    max_sites             INT           NOT NULL,          -- páginas activas permitidas
    ad_free               TINYINT(1)    NOT NULL DEFAULT 1,
    template_discount_pct INT           NOT NULL DEFAULT 0, -- descuento en plantillas extra al agotar el límite
    site_days             INT           NOT NULL DEFAULT 3, -- días que dura cada página creada con este plan
    bonus_coins           INT           NOT NULL DEFAULT 0, -- monedas que se abonan al comprar el plan
    topup_bonus_pct       INT           NOT NULL DEFAULT 0, -- % extra de monedas en recargas
    sort_order            INT           NOT NULL,          -- mayor = nivel superior
    duration_months       INT           NOT NULL DEFAULT 1, -- meses que dura cada compra (sin renovación automática)
    template_unlocks_per_month INT      NOT NULL DEFAULT 0, -- cupo mensual de plantillas de membresía (ver templates.membership_unlocks)
    html_uploads_per_month INT          NOT NULL DEFAULT 0, -- subidas de HTML propio por mes (el plan gratuito usa Access::FREE_MONTHLY_HTML_UPLOADS)
    is_active             TINYINT(1)    NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tiers_slug (slug),
    CONSTRAINT chk_tiers_price    CHECK (price_usd >= 0.01),
    CONSTRAINT chk_tiers_discount CHECK (template_discount_pct BETWEEN 0 AND 90),
    CONSTRAINT chk_tiers_ad_free  CHECK (ad_free IN (0, 1)),
    CONSTRAINT chk_tiers_days     CHECK (site_days >= 1),
    CONSTRAINT chk_tiers_duration CHECK (duration_months >= 1),
    CONSTRAINT chk_tiers_coins    CHECK (bonus_coins >= 0 AND topup_bonus_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_premium    TINYINT(1)   NOT NULL DEFAULT 0,
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
    membership_tier_id INT     NULL,                       -- NULL = plan gratuito
    membership_expires_at DATETIME NULL,                   -- UTC; NULL = el plan no vence (cuentas anteriores)
    coins         INT          NOT NULL DEFAULT 0,         -- saldo de monedas virtuales
    is_suspended  TINYINT(1)   NOT NULL DEFAULT 0,         -- cuenta suspendida por un admin
    suspended_reason VARCHAR(200) NULL,
    suspended_at  DATETIME     NULL,
    display_name  VARCHAR(30)  NULL,                       -- alias público del ranking (opt-in)
    show_in_rankings TINYINT(1) NOT NULL DEFAULT 0,        -- 1 = muestra su alias en el top de donadores
    bonus_tier_id INT          NULL,                       -- mejora TEMPORAL de plan (premio a creadores); no es el plan comprado
    bonus_tier_expires_at DATETIME NULL,                   -- UTC
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_display_name (display_name),
    KEY idx_users_tier (membership_tier_id),
    CONSTRAINT fk_users_tier FOREIGN KEY (membership_tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_bonus_tier FOREIGN KEY (bonus_tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL,
    CONSTRAINT chk_users_is_premium CHECK (is_premium IN (0, 1)),
    CONSTRAINT chk_users_is_admin   CHECK (is_admin   IN (0, 1)),
    CONSTRAINT chk_users_coins      CHECK (coins >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(120) NOT NULL,                  -- identificador estable
    name        VARCHAR(120) NOT NULL,
    kind        VARCHAR(8)   NOT NULL DEFAULT 'html',   -- html = archivo con {{campos}} · php = carpeta en /templates/php/<slug>
    category    VARCHAR(20)  NOT NULL DEFAULT 'romantico',
    file        VARCHAR(120) NOT NULL,                  -- archivo en /templates (solo basename)
    description VARCHAR(500) NOT NULL DEFAULT '',
    price_usd   DECIMAL(10,2) NOT NULL DEFAULT 0.00,    -- precio individual en USD (0 = sin compra suelta); lo fija el admin
    price_coins INT          NOT NULL DEFAULT 0,        -- costo en monedas al crear una página (0 = gratis)
    thumbnail   VARCHAR(160) NULL,                      -- basename en /public/assets/thumbs (opcional)
    is_premium  TINYINT(1)   NOT NULL DEFAULT 0,
    image_spec  TEXT         NULL,                      -- JSON con las fotos que pide la plantilla (ver TemplateImages); NULL = ninguna
    membership_unlocks TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = premium con CUPO mensual por membresía (cae a price_coins al agotarse); 0 = membresía la desbloquea sin límite (comportamiento clásico)
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    owner_user_id INT        NULL,                       -- autor de una plantilla pública de usuario (kind = 'utpl')
    review_status VARCHAR(10) NOT NULL DEFAULT 'approved', -- pending | approved | rejected | withdrawn
    review_note VARCHAR(255) NULL,
    credit_alias VARCHAR(30) NULL,                        -- alias de autoría (foto instantánea al aprobar)
    submitted_at DATETIME    NULL,
    reviewed_at DATETIME     NULL,
    reviewed_by INT          NULL,
    UNIQUE KEY uq_templates_slug (slug),
    KEY idx_templates_owner (owner_user_id, review_status),
    KEY idx_templates_review (review_status, kind, is_active),
    CONSTRAINT chk_templates_is_premium CHECK (is_premium IN (0, 1)),
    CONSTRAINT chk_templates_membership_unlocks CHECK (membership_unlocks IN (0, 1)),
    CONSTRAINT chk_templates_price_usd  CHECK (price_usd >= 0),
    CONSTRAINT chk_templates_price_coins CHECK (price_coins >= 0),
    CONSTRAINT chk_templates_is_active  CHECK (is_active  IN (0, 1)),
    CONSTRAINT fk_templates_owner    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_templates_reviewer FOREIGN KEY (reviewed_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sites (
    id          INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         NOT NULL,
    template_id INT         NOT NULL,
    slug        VARCHAR(32) NOT NULL,                   -- parte pública de la URL (aleatoria)
    data        TEXT        NOT NULL,                   -- JSON: your_name, partner_name, start_date, message
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME    NULL,                       -- UTC; NULL = sin caducidad (páginas anteriores a los planes temporales)
    UNIQUE KEY uq_sites_slug (slug),
    KEY idx_sites_user (user_id),
    KEY idx_sites_expires (expires_at),
    KEY idx_sites_template (template_id),
    -- Al borrar la cuenta desaparecen sus páginas; una plantilla con páginas NO se puede borrar.
    CONSTRAINT fk_sites_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sites_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_creations (
    id         INT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT        NOT NULL,
    tier_id    INT        NULL,                          -- plan efectivo al momento; NULL = gratuito
    kind       VARCHAR(8) NOT NULL,                      -- create | renew
    created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- UTC
    KEY idx_creations_user_time (user_id, created_at),
    CONSTRAINT fk_creations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id                    INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id               INT          NOT NULL,
    reference             VARCHAR(191) NOT NULL,        -- identificadorEnlaceComercio enviado a Wompi (vuelve en el webhook)
    amount_in_cents       INT          NOT NULL,        -- centavos de USD (entero, nunca float)
    currency              CHAR(3)      NOT NULL DEFAULT 'USD',
    status                VARCHAR(16)  NOT NULL DEFAULT 'PENDING', -- PENDING|APPROVED|DECLINED|VOIDED|ERROR
    wompi_transaction_id  VARCHAR(191) NULL,            -- IdTransaccion del webhook
    tier_id               INT          NULL,            -- membresía comprada (NULL si es plantilla)
    link_id               BIGINT       NULL,            -- idEnlace devuelto por POST /EnlacePago
    template_id           INT          NULL,            -- NULL = membresía Premium; con valor = compra de esa plantilla
    promo_code            VARCHAR(32)  NULL,            -- código aplicado al iniciar el pago
    coins                 INT          NULL,            -- recarga de monedas: total a abonar (fijado por el servidor al crear el pago)
    method                VARCHAR(12)  NOT NULL DEFAULT 'WOMPI', -- WOMPI|MANUAL|PROMO
    approved_by           INT          NULL,            -- admin que aprobó manualmente
    admin_note            VARCHAR(255) NULL,            -- motivo de la aprobación/anulación manual
    fulfilled_at          DATETIME     NULL,            -- cuándo se aplicó el efecto (plan/monedas/plantilla); NULL = aún no
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_reference (reference),
    KEY idx_payments_user (user_id),
    KEY idx_payments_template (template_id),
    KEY idx_payments_tier (tier_id),
    KEY idx_payments_status_created (status, created_at),
    KEY idx_payments_rank (status, fulfilled_at, user_id),
    CONSTRAINT fk_payments_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    -- Borrar una plantilla no debe borrar el historial de pagos.
    CONSTRAINT fk_payments_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_tier     FOREIGN KEY (tier_id)     REFERENCES membership_tiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de movimientos de monedas (append-only): auditoría y trazabilidad del saldo.
CREATE TABLE IF NOT EXISTS coin_transactions (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    delta         INT          NOT NULL,                -- + abono, - gasto
    balance_after INT          NOT NULL,
    reason        VARCHAR(24)  NOT NULL,                -- tier_bonus | topup | site_create | site_renew
    ref           VARCHAR(191) NOT NULL DEFAULT '',     -- referencia de pago o slug de la página
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_coin_tx_user (user_id, id),
    CONSTRAINT fk_coin_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitación de intentos de login (fuerza bruta)
CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,                    -- cabe una IPv6
    created_at INT         NOT NULL,                    -- unix time
    KEY idx_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- admin ---

-- Clave/valor de configuración editable desde el panel (banner, precio, etc.).
-- `key` es palabra reservada en MySQL: SIEMPRE entre acentos graves en las consultas.
CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(64) NOT NULL PRIMARY KEY,
    `value`    TEXT        NOT NULL,                    -- TEXT no admite DEFAULT: el código siempre escribe un valor
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promos (
    id               INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(32) NOT NULL,
    discount_percent INT         NOT NULL,
    is_active        TINYINT(1)  NOT NULL DEFAULT 1,
    expires_at       DATE        NULL,                  -- NULL = sin caducidad
    max_uses         INT         NOT NULL DEFAULT 0,    -- 0 = ilimitado
    uses             INT         NOT NULL DEFAULT 0,    -- se incrementa al aprobarse el pago
    scope            VARCHAR(12) NOT NULL DEFAULT 'all', -- all|tiers|templates
    tier_id          INT         NULL,                  -- con scope=tiers: limitar a este plan (NULL = cualquiera)
    note             VARCHAR(120) NULL,
    created_by       INT         NULL,
    created_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_promos_code (code),
    CONSTRAINT chk_promos_pct       CHECK (discount_percent BETWEEN 1 AND 100),
    CONSTRAINT chk_promos_is_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canjes de cupones: un usuario no puede usar el mismo código dos veces.
CREATE TABLE IF NOT EXISTS promo_redemptions (
    id         INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    promo_id   INT      NOT NULL,
    user_id    INT      NOT NULL,
    payment_id INT      NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_redemption (promo_id, user_id),
    KEY idx_redemption_user (user_id),
    CONSTRAINT fk_redemption_promo FOREIGN KEY (promo_id) REFERENCES promos(id) ON DELETE CASCADE,
    CONSTRAINT fk_redemption_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cupo mensual de plantillas de membresía: una fila por plantilla DISTINTA desbloqueada gratis
-- ese mes calendario (hora de El Salvador); ver Access::templateUnlockUsage() y templates.membership_unlocks.
CREATE TABLE IF NOT EXISTS template_unlocks (
    id          INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT      NOT NULL,
    template_id INT      NOT NULL,
    tier_id     INT      NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tplunlocks_user_month (user_id, created_at),
    KEY idx_tplunlocks_user_tpl (user_id, template_id),
    CONSTRAINT fk_tplunlocks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tplunlocks_tpl  FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos que suben los usuarios para una página (archivos en public/uploads/sites/{slug}/, ver ImageStore).
CREATE TABLE IF NOT EXISTS site_images (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metadatos del HTML propio de una página (el archivo vive FUERA del webroot: storage/user_html/{slug}/).
CREATE TABLE IF NOT EXISTS user_html_sites (
    site_id    INT          NOT NULL PRIMARY KEY,
    sha256     CHAR(64)     NOT NULL,
    bytes      INT          NOT NULL,
    has_assets TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_html_site FOREIGN KEY (site_id) REFERENCES user_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de subidas de HTML propio (cupo mensual por plan). No se borra al borrar la página: evita burlar el cupo.
CREATE TABLE IF NOT EXISTS html_uploads (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    site_id    INT          NULL,
    tier_id    INT          NULL,
    sha256     CHAR(64)     NOT NULL,
    bytes      INT          NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_html_uploads_user_month (user_id, created_at),
    CONSTRAINT fk_html_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La plantilla oculta 'html-propio' (kind = 'user', compartida por las páginas de HTML propio) se siembra en
-- db_migrate_v11(): aquí no, porque este script corre antes que las migraciones que crean las columnas de `templates`.

-- Rastro de auditoría de acciones administrativas (append-only).
CREATE TABLE IF NOT EXISTS admin_audit (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NULL,
    action     VARCHAR(64)  NOT NULL,
    detail     VARCHAR(500) NOT NULL DEFAULT '',
    ip         VARCHAR(45)  NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_user (user_id),
    -- Borrar una cuenta no debe borrar su rastro de auditoría.
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planes (ON DUPLICATE KEY: re-aplicar el esquema deja siempre los valores vigentes).
INSERT INTO membership_tiers (slug, name, price_usd, max_sites, ad_free, template_discount_pct, site_days, bonus_coins, topup_bonus_pct, sort_order, duration_months, template_unlocks_per_month, html_uploads_per_month) VALUES
    ('romantico', 'Romántico', 1.00,  2, 0, 0,  3,  10,  0, 1, 1, 1, 3),
    ('pareja',    'Pareja',    3.99,  5, 1, 0,  7,  45, 10, 2, 1, 3, 6),
    ('eterno',    'Eterno',    7.99, 12, 1, 0, 14, 100, 20, 3, 2, 6, 12)
ON DUPLICATE KEY UPDATE name = VALUES(name), price_usd = VALUES(price_usd), max_sites = VALUES(max_sites), ad_free = VALUES(ad_free),
    template_discount_pct = VALUES(template_discount_pct), site_days = VALUES(site_days), bonus_coins = VALUES(bonus_coins),
    topup_bonus_pct = VALUES(topup_bonus_pct), sort_order = VALUES(sort_order),
    duration_months = VALUES(duration_months);
    -- Nota: template_unlocks_per_month NO se incluye en el UPDATE para no pisar el valor que el admin
    -- ya haya ajustado desde el panel en una base existente; solo se siembra en el INSERT inicial.

INSERT IGNORE INTO templates (slug, name, file, description, is_premium, price_coins) VALUES
    ('free-minimal',  'Minimal',             'free-minimal.html',  'Contador de días y carta, sin adornos.', 0, 0),
    ('premium-heart', 'Corazones (Premium)', 'premium-heart.html', 'Lluvia de corazones animada.',           1, 5);

-- ------------------------------------------------- top de donadores (v12) ---

-- Meses ya cerrados (premios del top mensual otorgados). ym = 'YYYY-MM' en hora de El Salvador.
CREATE TABLE IF NOT EXISTS awards_closed_months (
    ym        CHAR(7)  NOT NULL PRIMARY KEY,
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_badges (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    badge_key  VARCHAR(40) NOT NULL,
    period     VARCHAR(7)  NOT NULL DEFAULT '',            -- 'YYYY-MM' en insignias mensuales; '' en hitos
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_badges (user_id, badge_key, period),
    CONSTRAINT fk_user_badges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de concesiones: el UNIQUE (user_id, grant_key) garantiza que un premio se otorga una sola vez.
CREATE TABLE IF NOT EXISTS award_grants (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    grant_key  VARCHAR(80) NOT NULL,                       -- 'month:2026-09:1' | 'milestone:1000'
    kind       VARCHAR(20) NOT NULL,                       -- month | milestone
    coins      INT         NOT NULL DEFAULT 0,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_award_grants (user_id, grant_key),
    KEY idx_award_grants_created (created_at),
    CONSTRAINT fk_award_grants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- economía de creadores (v13) ---

-- Ganancias de los creadores. UNIQUE (template_id, ref): un mismo uso nunca se registra dos veces.
-- Sin FK a creator_id A PROPÓSITO: una FK toma un bloqueo compartido sobre la fila del creador y dos usuarios que se
-- compran plantillas mutuamente podrían entrar en deadlock. Las filas huérfanas (usuario borrado) se ignoran al pagar.
-- share_coins se paga aparte (Creators::settle) con status pending -> paid; el pago va al libro coin_transactions.
CREATE TABLE IF NOT EXISTS template_earnings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT         NOT NULL,
    creator_id  INT         NOT NULL,
    buyer_id    INT         NULL,
    site_id     INT         NULL,
    ref         VARCHAR(80) NOT NULL,                      -- 'create:{siteId}' | 'renew:{siteId}:{YmdHis}'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
