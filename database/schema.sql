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
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_tier (membership_tier_id),
    CONSTRAINT fk_users_tier FOREIGN KEY (membership_tier_id) REFERENCES membership_tiers(id) ON DELETE SET NULL,
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
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_templates_slug (slug),
    CONSTRAINT chk_templates_is_premium CHECK (is_premium IN (0, 1)),
    CONSTRAINT chk_templates_price_usd  CHECK (price_usd >= 0),
    CONSTRAINT chk_templates_price_coins CHECK (price_coins >= 0),
    CONSTRAINT chk_templates_is_active  CHECK (is_active  IN (0, 1))
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
INSERT INTO membership_tiers (slug, name, price_usd, max_sites, ad_free, template_discount_pct, site_days, bonus_coins, topup_bonus_pct, sort_order, duration_months) VALUES
    ('romantico', 'Romántico', 1.00,  2, 0, 0,  3,  10,  0, 1, 1),
    ('pareja',    'Pareja',    3.99,  5, 1, 0,  7,  45, 10, 2, 1),
    ('eterno',    'Eterno',    7.99, 12, 1, 0, 14, 100, 20, 3, 2)
ON DUPLICATE KEY UPDATE name = VALUES(name), price_usd = VALUES(price_usd), max_sites = VALUES(max_sites), ad_free = VALUES(ad_free),
    template_discount_pct = VALUES(template_discount_pct), site_days = VALUES(site_days), bonus_coins = VALUES(bonus_coins),
    topup_bonus_pct = VALUES(topup_bonus_pct), sort_order = VALUES(sort_order),
    duration_months = VALUES(duration_months);

INSERT IGNORE INTO templates (slug, name, file, description, is_premium, price_coins) VALUES
    ('free-minimal',  'Minimal',             'free-minimal.html',  'Contador de días y carta, sin adornos.', 0, 0),
    ('premium-heart', 'Corazones (Premium)', 'premium-heart.html', 'Lluvia de corazones animada.',           1, 5);
