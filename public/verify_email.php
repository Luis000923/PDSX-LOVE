<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}
if (EmailVerification::isVerified($user)) {
    redirect(auth_next('create.php'));
}

$uid = (int) $user['id'];
$error = null;
$info  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['resend'])) {
        $r = EmailVerification::issue($uid);
        $r['ok'] ? $info = 'Te enviamos un código nuevo.' : $error = $r['error'];
    } else {
        $r = EmailVerification::verify($uid, (string) ($_POST['code'] ?? ''));
        if ($r['ok']) {
            flash('¡Correo verificado! Bienvenida/o a LovePages.');
            redirect(auth_next('create.php'));
        }
        $error = $r['error'];
    }
}

// Correo enmascarado: j***@gmail.com
$parts = explode('@', (string) $user['email'], 2);
$masked = mb_substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '');
$action = url('verify_email.php') . auth_next_qs();

page_start('Verifica tu correo');
?>
<h1 class="text-2xl font-bold mt-4 mb-2">Verifica tu correo</h1>
<p class="mb-4 text-sm text-slate-600">Enviamos un código de 6 dígitos a <strong><?= e($masked) ?></strong>. Caduca en <?= intdiv(EmailVerification::TTL_SECS, 60) ?> minutos. Revisa también la carpeta de spam.</p>
<?php if ($error): ?><p role="alert" class="mb-4 text-sm text-rose-700"><?= e($error) ?></p><?php endif; ?>
<?php if ($info): ?><p role="status" class="mb-4 text-sm text-emerald-800"><?= e($info) ?></p><?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="space-y-4" autocomplete="off">
  <?= csrf_field() ?>
  <label class="block text-sm font-semibold" for="code">Código de verificación</label>
  <input id="code" class="<?= INPUT_CLS ?> tracking-[0.5em] text-center text-xl" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus autocomplete="one-time-code">
  <button class="<?= BTN_CLS ?>">Verificar</button>
</form>
<form method="post" action="<?= e($action) ?>" class="mt-4 text-center"><?= csrf_field() ?>
  <button name="resend" value="1" class="min-h-[44px] text-sm font-semibold text-rose-700 underline underline-offset-2">Reenviar código</button>
</form>
<p class="mt-4 text-center text-sm text-slate-500">¿Otro correo? <a class="text-rose-600 font-semibold" href="<?= e(url('logout.php')) ?>">Cerrar sesión</a></p>
<?php page_end();
