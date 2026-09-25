<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

const MAX_ATTEMPTS = 8;      // fallos permitidos por IP...
const WINDOW_SECS  = 900;    // ...en 15 minutos

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pdo = db();
    $ip  = client_ip();
    $now = time();

    $pdo->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([$now - WINDOW_SECS]);
    // Límite por IP y por cuenta (clave "e:" + hash del correo, cabe en la columna ip): frena también ataques distribuidos.
    $emailKey = 'e:' . substr(hash('sha256', strtolower(trim((string) ($_POST['email'] ?? '')))), 0, 40);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    $byIp = (int) $st->fetchColumn();
    $st->execute([$emailKey]);
    $byEmail = (int) $st->fetchColumn();

    if ($byIp >= MAX_ATTEMPTS || $byEmail >= MAX_ATTEMPTS) {
        http_response_code(429);
        $error = 'Demasiados intentos. Inténtalo en unos minutos.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');

        $st = $pdo->prepare('SELECT id, password_hash, is_suspended FROM users WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch();

        // Se verifica siempre contra un hash (dummy si no existe) para igualar tiempos de respuesta.
        $hash = $row['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        if (password_verify($pass, $hash) && $row && (int) $row['is_suspended'] === 1) {
            $error = 'Tu cuenta está suspendida. Contacta a soporte.';   // sin revelar el motivo
        } elseif (password_verify($pass, $hash) && $row) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            }
            $pdo->prepare('DELETE FROM login_attempts WHERE ip IN (?, ?)')->execute([$ip, $emailKey]);
            login_user((int) $row['id']);
            redirect(auth_next('dashboard.php'));
        }
        if ($error === null) {
            $ins = $pdo->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)');
            $ins->execute([$ip, $now]);
            $ins->execute([$emailKey, $now]);
            $error = 'Correo o contraseña incorrectos.';
        }
    }
}

$googleBtn = google_login_button(auth_next_value());   // ?next=premium se conserva en el viaje a Google
page_start('Entrar');
?>
<h1 class="text-2xl font-bold mt-4 mb-6">Bienvenido de vuelta</h1>
<?php if ($error): ?><p class="mb-4 text-sm text-rose-700"><?= e($error) ?></p><?php endif; ?>
<?php if ($googleBtn !== ''): ?>
  <?= $googleBtn ?>
  <p class="my-4 text-center text-xs uppercase tracking-widest text-slate-400">o con correo</p>
<?php endif; ?>
<form method="post" class="space-y-4">
  <?= csrf_field() ?>
  <input class="<?= INPUT_CLS ?>" type="email" name="email" placeholder="Correo" required autocomplete="email">
  <input class="<?= INPUT_CLS ?>" type="password" name="password" placeholder="Contraseña" required autocomplete="current-password">
  <button class="<?= BTN_CLS ?>">Entrar</button>
</form>
<p class="mt-6 text-sm text-center text-slate-500">¿Sin cuenta? <a class="text-rose-600 font-semibold" href="<?= e(url('register.php') . auth_next_qs()) ?>">Regístrate</a></p>
<?php page_end();
