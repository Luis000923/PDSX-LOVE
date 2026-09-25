<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
// Código de quien te invitó: llega en el enlace (?ref=) y viaja en un campo oculto; solo se acepta con formato válido.
$refCode = Referrals::normalize((string) ($_POST['ref'] ?? $_GET['ref'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    // Alias público: es el nombre que se muestra en el Top de donadores (y como autor si publicas plantillas).
    $aliasIn = Ranking::validateAlias((string) ($_POST['alias'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Correo inválido.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) { // bcrypt trunca a 72 bytes
        $error = 'La contraseña debe tener entre 8 y 72 caracteres.';
    } elseif ($aliasIn['error'] !== null) {
        $error = $aliasIn['error'];
    } elseif (Ranking::aliasTaken($aliasIn['value'])) {
        $error = 'Ese alias ya está en uso. Elige otro.';   // no revela nada sobre los correos registrados
    } else {
        try {
            $created = Registration::create($email, $pass, $aliasIn['value'], $refCode);
            if ($created['id'] === null) {
                $error = $created['error'];
            } else {
                login_user($created['id']);
                if (EmailVerification::required()) {
                    EmailVerification::issue($created['id']);   // si el envío falla, la pantalla del código permite reenviar
                    redirect('verify_email.php' . auth_next_qs());
                }
                redirect(auth_next('index.php'));
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $error = 'Error inesperado.';
        }
    }
}

$googleBtn = google_login_button(auth_next_value());   // ?next=premium se conserva en el viaje a Google
page_start('Crear cuenta');
?>
<h1 class="text-2xl font-bold mt-4 mb-2">Crea tu cuenta</h1>
<?php if (auth_next_qs() !== ''): ?><p class="mb-6 text-sm text-slate-600">Es rápido: en cuanto la crees pasas directo al pago de Premium.</p><?php else: ?><div class="mb-4"></div><?php endif; ?>
<?php if ($error): ?><p class="mb-4 text-sm text-rose-700"><?= e($error) ?></p><?php endif; ?>
<?php if ($googleBtn !== ''): ?>
  <?= $googleBtn ?>
  <p class="my-4 text-center text-xs uppercase tracking-widest text-slate-400">o con correo</p>
  <p class="mb-4 text-xs text-slate-500">Con Google te preguntamos el alias nada más entrar.</p>
<?php endif; ?>
<form method="post" class="space-y-4" autocomplete="on">
  <?= csrf_field() ?>
  <?php if ($refCode !== ''): ?><input type="hidden" name="ref" value="<?= e($refCode) ?>"><p class="text-sm text-emerald-800">Te invitó alguien de LovePages 💌</p><?php endif; ?>
  <input class="<?= INPUT_CLS ?>" type="email" name="email" placeholder="Correo" required maxlength="254" autocomplete="email">
  <input class="<?= INPUT_CLS ?>" type="password" name="password" placeholder="Contraseña (mín. 8)" required minlength="8" maxlength="72" autocomplete="new-password">
  <div>
    <label class="block text-sm font-semibold mb-1" for="alias">Tu alias público</label>
    <input id="alias" class="<?= INPUT_CLS ?>" type="text" name="alias" value="<?= e((string) ($_POST['alias'] ?? '')) ?>" placeholder="Ej.: Luna_Sv" required minlength="<?= Ranking::ALIAS_MIN ?>" maxlength="<?= Ranking::ALIAS_MAX ?>" autocomplete="nickname" aria-describedby="alias-ayuda">
    <p id="alias-ayuda" class="mt-1 text-xs text-slate-600">Es el nombre que se mostrará en el <strong>Top de donadores</strong> (y como autor si publicas plantillas). Nunca mostramos tu correo ni tu nombre real. Puedes cambiarlo o desactivarlo cuando quieras. Letras, números, espacios y . _ -</p>
  </div>
  <button class="<?= BTN_CLS ?>">Registrarme</button>
</form>
<p class="mt-6 text-sm text-center text-slate-500">¿Ya tienes cuenta? <a class="text-rose-600 font-semibold" href="<?= e(url('login.php') . auth_next_qs()) ?>">Entrar</a></p>
<?php page_end();
