<?php
declare(strict_types=1);

/**
 * Importa los datos de una base SQLite antigua de LovePages a la base MySQL configurada (DB_*).
 *
 *   php bin/import_sqlite.php /ruta/store.sqlite
 *
 * - Solo CLI. Requiere la extensión pdo_sqlite (viene en la imagen Docker).
 * - Conserva los ids (las claves foráneas siguen valiendo) y copia las tablas de negocio:
 *   users, templates, user_sites, payments, promos, settings, admin_audit.
 *   `login_attempts` es efímera y no se copia.
 * - Se niega a correr si MySQL ya tiene usuarios, páginas o pagos: es una carga inicial, no una fusión.
 * - Todo ocurre en UNA transacción: si algo falla, MySQL queda como estaba (solo DML, sin DDL).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por CLI.\n");
}

define('NO_SESSION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

/** Orden que respeta las claves foráneas. */
const IMPORT_TABLES = ['users', 'templates', 'user_sites', 'payments', 'promos', 'settings', 'admin_audit'];

$path = (string) ($_SERVER['argv'][1] ?? '');
if ($path === '' || !is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Uso: php bin/import_sqlite.php /ruta/a/store.sqlite\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Falta la extensión pdo_sqlite.\n");
    exit(1);
}

$src = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$dst = db();   // crea el esquema si la base está vacía

foreach (['users', 'user_sites', 'payments'] as $t) {
    if ((int) $dst->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() > 0) {
        fwrite(STDERR, "MySQL ya tiene datos en `$t`: la importación es solo para una base nueva. Abortado sin cambios.\n");
        exit(1);
    }
}

$dst->beginTransaction();
try {
    // Las plantillas base sembradas por schema.sql se reemplazan por las de la base origen (mismos ids).
    $dst->exec('SET FOREIGN_KEY_CHECKS = 0');
    $dst->exec('DELETE FROM templates');

    foreach (IMPORT_TABLES as $table) {
        $exists = $src->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '$table'")->fetchColumn();
        if (!$exists) {
            echo str_pad($table, 12) . " (no existe en el origen)\n";
            continue;
        }
        // Solo las columnas que existen en ambos lados: tolera bases SQLite de versiones anteriores.
        $srcCols = array_column($src->query("PRAGMA table_info(\"$table\")")->fetchAll(), 'name');
        $dstCols = $dst->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$table'")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_values(array_intersect($srcCols, $dstCols));

        $quoted = implode(', ', array_map(static fn(string $c): string => "`$c`", $cols));
        $ins = $dst->prepare("INSERT INTO `$table` ($quoted) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')');

        $n = 0;
        foreach ($src->query("SELECT $quoted FROM \"$table\"") as $row) {
            $ins->execute(array_values($row));
            $n++;
        }
        echo str_pad($table, 12) . " $n filas\n";
    }
    $dst->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Integridad: ninguna fila huérfana tras desactivar las comprobaciones durante la carga.
    $orphans = (int) $dst->query(
        'SELECT (SELECT COUNT(*) FROM user_sites s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL)
              + (SELECT COUNT(*) FROM user_sites s LEFT JOIN templates t ON t.id = s.template_id WHERE t.id IS NULL)
              + (SELECT COUNT(*) FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL)'
    )->fetchColumn();
    if ($orphans > 0) {
        throw new RuntimeException("$orphans filas huérfanas en el origen (referencias a usuarios/plantillas inexistentes).");
    }

    // Igual que db_migrate(): si el origen es anterior al panel y no trae administradores,
    // el usuario más antiguo pasa a serlo (db() migró la base vacía antes de importar, sin usuarios).
    if ((int) $dst->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() === 0) {
        $oldest = $dst->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($oldest !== null && $oldest !== false) {
            $dst->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$oldest]);
            echo "Sin administradores en el origen: #$oldest (el más antiguo) queda como administrador.\n";
        }
    }
    $dst->commit();
} catch (Throwable $e) {
    if ($dst->inTransaction()) {
        $dst->rollBack();
    }
    $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, 'Importación fallida, MySQL sin cambios: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Importación completada.\n";
