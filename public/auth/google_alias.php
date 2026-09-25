<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Paso 3 del acceso con Google: pide el alias público.
 *
 * Por qué una pantalla aparte y no un campo más en register.php: el alias solo hace falta cuando la
 * cuenta aún no lo tiene, y en ese momento ya sabemos quién es (Google acaba de verificar el correo).
 * Pedirlo antes sería un formulario más que se puede abandonar, o acertar el destino a ciegas.
 *
 * Al entrar con correo y contraseña el alias se pide en register.php, junto a la contraseña. Aquí no
 * hay contraseña que inventar: solo el alias y su explicación.
 *
 * A quién manda el callback: a toda cuenta de Google sin alias (GoogleAccount::needsAliasPrompt), no
 * solo a las recién creadas, para que a quien ya existía no se le escape. Decir «ahora no» graba la
 * negativa (users.alias_dismissed_at) y no se vuelve a preguntar.
 *
 * Es idempotente: si la cuenta ya tiene alias se pasa de largo. Así recargar no vuelve a preguntar.
 */

$user = require_login();
$uid  = (int) $user['id'];

// `?next=` solo admite la lista blanca de auth_next_value(): premium|code. No hay open redirect.
$target = auth_next_path(auth_next_value(), 'index.php');

// display_name no viene en current_user() (ver bootstrap.php:160), así que se lee.
$st = db()->prepare('SELECT display_name FROM users WHERE id = ?');
$st->execute([$uid]);
$current = (string) ($st->fetchColumn() ?: '');
if ($current !== '') {
    redirect($target);   // ya lo tiene (o lo saltó): no se vuelve a preguntar
}

$error   = null;
$entered = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();   // 405 si no es POST + CSRF estricto
    if (($_POST['skip'] ?? '') !== '') {
        // Se guarda la negativa: sin esto, el callback volvería a mandar aquí en cada acceso con Google.
        // El alias sigue siendo opcional (display_name admite NULL) y se puede poner luego en el perfil.
        GoogleAccount::dismissAliasPrompt($uid);
        redirect($target);
    }
    $v = Ranking::validateAlias((string) ($_POST['alias'] ?? ''));
    $entered = $v['value'];
    if ($v['error'] === null && Ranking::aliasTaken($v['value'])) {
        $v['error'] = 'Ese alias ya está en uso. Elige otro.';   // no revela qué correos existen
    }
    if ($v['error'] !== null) {
        $error = (string) $v['error'];
    } else {
        // saveOptIn() cobra 0 por el PRIMER alias: $isChange es false mientras display_name esté vacío
        // (Ranking.php:228), así que esto no gasta monedas. show_in_rankings = 1: se pide a la vista.
        $saved = Ranking::saveOptIn($uid, true, $v['value']);
        if ($saved['ok']) {
            flash('¡Alias guardado! Así te verán en el Top de donadores.');
            redirect($target);
        }
        $error = (string) $saved['error'];
    }
}

page_start('Elige tu alias');
?>
<h1 class="text-2xl font-bold mt-4 mb-2">Ya estás dentro</h1>
<p class="mb-6 text-sm text-slate-600">Solo falta que elijas tu alias público. Es el nombre que se mostrará en el <strong>Top de donadores</strong>, y como autor si publicas plantillas.</p>
<?php if ($error): ?><p class="mb-4 text-sm text-rose-700" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="space-y-4" autocomplete="on">
  <?= csrf_field() ?>
  <div>
    <label class="block text-sm font-semibold mb-1" for="alias">Tu alias público</label>
    <input id="alias" class="<?= INPUT_CLS ?>" type="text" name="alias" value="<?= e($entered) ?>" placeholder="Ej.: Luna_Sv" required minlength="<?= Ranking::ALIAS_MIN ?>" maxlength="<?= Ranking::ALIAS_MAX ?>" autocomplete="nickname" aria-describedby="alias-ayuda" <?= $error !== null ? 'aria-invalid="true"' : '' ?>>
    <p id="alias-ayuda" class="mt-1 text-xs text-slate-600">Gratis, y no se puede reutilizar. Nunca mostramos tu correo ni tu nombre real. Puedes cambiarlo o desactivarlo cuando quieras, aunque cambiarlo después sí cuesta monedas. Entre <?= Ranking::ALIAS_MIN ?> y <?= Ranking::ALIAS_MAX ?> caracteres: letras, números, espacios y . _ -</p>
  </div>
  <button class="<?= BTN_CLS ?>">Guardar alias</button>
</form>
<form method="post" class="mt-3">
  <?= csrf_field() ?>
  <button name="skip" value="1" class="text-sm text-slate-500 hover:text-slate-700 underline min-h-[44px]">Ahora no, más tarde</button>
</form>
<?php page_end();
