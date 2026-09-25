-- LovePages: esquema (SQLite). Idempotente: se puede re-ejecutar sin daño.
-- Las columnas añadidas después de la v1 se aplican a bases existentes en db_migrate()
-- (config/database.php), que usa PRAGMA user_version como marca de versión.

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT    NOT NULL,
    is_premium    INTEGER NOT NULL DEFAULT 0 CHECK (is_premium IN (0,1)),
    is_admin      INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0,1)),
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    slug        TEXT    NOT NULL UNIQUE,         -- identificador estable
    name        TEXT    NOT NULL,
    file        TEXT    NOT NULL,                -- archivo en /templates (solo basename)
    description TEXT    NOT NULL DEFAULT '',
    price_cop   INTEGER NOT NULL DEFAULT 0,      -- precio de referencia mostrado en el catálogo
    thumbnail   TEXT,                            -- basename en /public/assets/thumbs (opcional)
    is_premium  INTEGER NOT NULL DEFAULT 0 CHECK (is_premium IN (0,1)),
    is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1))
);

CREATE TABLE IF NOT EXISTS user_sites (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id)     ON DELETE CASCADE,
    template_id INTEGER NOT NULL REFERENCES templates(id),
    slug        TEXT    NOT NULL UNIQUE,         -- parte pública de la URL (aleatoria)
    data        TEXT    NOT NULL,                -- JSON: your_name, partner_name, start_date, message
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_sites_user ON user_sites(user_id);

CREATE TABLE IF NOT EXISTS payments (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id               INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reference             TEXT    NOT NULL UNIQUE,   -- referencia enviada a Wompi
    amount_in_cents       INTEGER NOT NULL,
    currency              TEXT    NOT NULL DEFAULT 'COP',
    status                TEXT    NOT NULL DEFAULT 'PENDING', -- PENDING|APPROVED|DECLINED|VOIDED|ERROR
    wompi_transaction_id  TEXT,
    promo_code            TEXT,                      -- código aplicado al iniciar el pago
    created_at            TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at            TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_payments_user ON payments(user_id);

-- Limitación de intentos de login (fuerza bruta)
CREATE TABLE IF NOT EXISTS login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT NOT NULL,
    created_at INTEGER NOT NULL                     -- unix time
);
CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip, created_at);

-- ---------------------------------------------------------------- admin ---

-- Clave/valor de configuración editable desde el panel (banner, precio, etc.).
CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS promos (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    code             TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    discount_percent INTEGER NOT NULL CHECK (discount_percent BETWEEN 1 AND 90),
    is_active        INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    expires_at       TEXT,                          -- Y-m-d, NULL = sin caducidad
    max_uses         INTEGER NOT NULL DEFAULT 0,    -- 0 = ilimitado
    uses             INTEGER NOT NULL DEFAULT 0,    -- se incrementa al aprobarse el pago
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Rastro de auditoría de acciones administrativas (append-only).
CREATE TABLE IF NOT EXISTS admin_audit (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     TEXT NOT NULL,
    detail     TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON admin_audit(created_at);

INSERT OR IGNORE INTO templates (slug, name, file, description, is_premium) VALUES
    ('free-minimal',  'Minimal',             'free-minimal.html',  'Contador de días y carta, sin adornos.', 0),
    ('premium-heart', 'Corazones (Premium)', 'premium-heart.html', 'Lluvia de corazones animada.',           1);
