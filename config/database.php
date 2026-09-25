<?php
declare(strict_types=1);

/**
 * Conexión PDO a SQLite (singleton perezoso).
 * Si la base aún no tiene tablas, aplica database/schema.sql automáticamente;
 * si la tiene pero es de una versión anterior, aplica las migraciones pendientes.
 */

/** Versión de esquema esperada por el código (PRAGMA user_version). */
const DB_SCHEMA_VERSION = 2;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . env('DB_PATH', ROOT . '/database/store.sqlite'), null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // sentencias preparadas reales
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');

    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < DB_SCHEMA_VERSION) {
        db_migrate($pdo);
    }
    return $pdo;
}

/**
 * Lleva la base al esquema actual. Idempotente y seguro sobre bases ya pobladas:
 * schema.sql solo usa CREATE ... IF NOT EXISTS / INSERT OR IGNORE, y las columnas
 * nuevas se añaden solo si faltan (SQLite no tiene ADD COLUMN IF NOT EXISTS).
 */
function db_migrate(PDO $pdo): void
{
    // PRAGMA no admite parámetros; $table/$column son literales del código, nunca entrada del usuario.
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
        $columns = array_column($pdo->query("PRAGMA table_info(\"$table\")")->fetchAll(), 'name');
        // Lista vacía = la tabla aún no existe: la creará schema.sql, ya con la columna.
        if ($columns !== [] && !in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN $column $definition");
        }
    };

    // v1 -> v2: panel de administración. Las columnas van ANTES de schema.sql porque su
    // INSERT OR IGNORE sobre `templates` ya escribe en `description`.
    $addColumn('users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0,1))');
    $addColumn('templates', 'description', "TEXT NOT NULL DEFAULT ''");
    $addColumn('templates', 'price_cop', 'INTEGER NOT NULL DEFAULT 0');
    $addColumn('templates', 'thumbnail', 'TEXT');
    $addColumn('payments', 'promo_code', 'TEXT');

    $pdo->exec((string) file_get_contents(ROOT . '/database/schema.sql'));

    // El primer usuario registrado es el administrador principal. En instalaciones
    // que ya tenían usuarios antes de existir el panel, se promueve al más antiguo.
    $hasAdmin = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($hasAdmin === 0) {
        $pdo->exec('UPDATE users SET is_admin = 1 WHERE id = (SELECT MIN(id) FROM users)');
    }

    $pdo->exec('PRAGMA user_version = ' . DB_SCHEMA_VERSION);
}
