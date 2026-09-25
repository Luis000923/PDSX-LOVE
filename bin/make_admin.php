<?php
declare(strict_types=1);

/**
 * Designa (o revoca) el administrador de LovePages desde la línea de comandos.
 *
 *   php bin/make_admin.php                  Muestra los administradores actuales.
 *   php bin/make_admin.php correo@ej.com    Concede is_admin = 1 a ese usuario.
 *   php bin/make_admin.php correo@ej.com --revoke
 *
 * Es la ÚNICA vía para crear administradores: ni register.php ni Google OAuth
 * asignan is_admin (siempre nace en 0).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por CLI.\n");
}

define('NO_SESSION', true);          // sin sesión ni cabeceras HTTP
require dirname(__DIR__) . '/src/bootstrap.php';

$args   = array_map(strval(...), array_slice((array) ($_SERVER['argv'] ?? []), 1));   // $argv puede no existir según register_argc_argv
$revoke = in_array('--revoke', $args, true);
$email  = strtolower(trim((string) (array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')))[0] ?? '')));

$pdo = db();   // aplica el esquema/migraciones si hace falta

if ($email === '') {
    $admins = $pdo->query('SELECT id, email, created_at FROM users WHERE is_admin = 1 ORDER BY id')->fetchAll();
    $total  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "Usuarios: $total · administradores: " . count($admins) . "\n";
    foreach ($admins as $a) {
        echo "  #{$a['id']}  {$a['email']}  (alta {$a['created_at']})\n";
    }
    if (!$admins) {
        echo "  (ninguno) Ejecuta: php bin/make_admin.php tu-correo@dominio\n";
    }
    exit(0);
}

$st = $pdo->prepare('SELECT id, email, is_admin FROM users WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    fwrite(STDERR, "No existe ningún usuario con el correo «{$email}». Regístralo primero en la web.\n");
    exit(1);
}

if ($revoke) {
    $others = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() - (int) $user['is_admin'];
    if ($others < 1) {
        fwrite(STDERR, "No se puede revocar: quedaría la instalación sin administradores.\n");
        exit(1);
    }
}

$pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([$revoke ? 0 : 1, $user['id']]);
$pdo->prepare('INSERT INTO admin_audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')
    ->execute([(int) $user['id'], $revoke ? 'admin.revoke' : 'admin.grant', 'vía bin/make_admin.php', 'cli']);

echo ($revoke ? 'Revocado' : 'Concedido') . " el acceso de administración a {$user['email']} (#{$user['id']}).\n";
