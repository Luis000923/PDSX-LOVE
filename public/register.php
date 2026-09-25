<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Correo inválido.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) { // bcrypt trunca a 72 bytes
        $error = 'La contraseña debe tener entre 8 y 72 caracteres.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            // El primer usuario de la instalación queda como administrador principal.
            $first = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
            $st = $pdo->prepare('INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)');
            $st->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $first ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            login_user($id);
            redirect('create.php');
        } catch (PDOException $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            // 23000 = violación de UNIQUE. Mensaje genérico para no filtrar qué correos existen.
            $error = $ex->getCode() === '23000' ? 'No se pudo crear la cuenta con esos datos.' : 'Error inesperado.';
            if ($ex->getCode() !== '23000') {
                error_log($ex->getMessage());
            }
        }
    }
}

page_start('Crear cuenta');
?>
<h1 class="text-2xl font-bold mt-4 mb-6">Crea tu cuenta</h1>
<?php if ($error): ?><p class="mb-4 text-sm text-rose-700"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="space-y-4" autocomplete="on">
  <?= csrf_field() ?>
  <input class="<?= INPUT_CLS ?>" type="email" name="email" placeholder="Correo" required maxlength="254" autocomplete="email">
  <input class="<?= INPUT_CLS ?>" type="password" name="password" placeholder="Contraseña (mín. 8)" required minlength="8" maxlength="72" autocomplete="new-password">
  <button class="<?= BTN_CLS ?>">Registrarme</button>
</form>
<p class="mt-6 text-sm text-center text-slate-500">¿Ya tienes cuenta? <a class="text-rose-600 font-semibold" href="<?= e(url('login.php')) ?>">Entrar</a></p>
<?php page_end();
