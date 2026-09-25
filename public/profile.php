<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Perfil: correo, contraseña y estado del plan. Solo autenticados. */
$user = require_login();
$uid  = (int) $user['id'];

$errors = ['email' => null, 'password' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();   // 405 si no es POST + CSRF estricto
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'email') {
        $errors['email'] = Profile::changeEmail($uid, (string) ($_POST['email'] ?? ''));
        if ($errors['email'] === null) {
            flash('Correo actualizado.');
            redirect('profile.php');
        }
    } elseif ($action === 'password') {
        $errors['password'] = Profile::changePassword(
            $uid,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? ''),
        );
        if ($errors['password'] === null) {
            // Cambio de credenciales: se rota el id de sesión y el token CSRF.
            login_user($uid);
            flash('Contraseña actualizada.');
            redirect('profile.php');
        }
    } else {
        http_response_code(400);
        exit('Acción desconocida.');
    }
}

$tier    = Access::userTier($user);
$sites   = Access::siteCount($uid);
$allowed = Access::siteAllowance($user);
$usage   = Access::siteUsage($user);
$isMonth = $usage['mode'] === 'month';
$pUsed   = $isMonth ? $usage['used'] : $sites;
$pMax    = $isMonth ? $usage['allowed'] : $allowed;
$pFull   = $isMonth && $usage['remaining'] <= 0;
$pct     = $pMax > 0 ? min(100, (int) round($pUsed / $pMax * 100)) : 0;
$since   = null;
$stc = db()->prepare('SELECT created_at FROM users WHERE id = ?');
$stc->execute([$uid]);
$created = (string) $stc->fetchColumn();
if ($created !== '' && ($ts = strtotime($created)) !== false) {
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $since = (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}
$ico = static fn (string $n, string $cls = ''): string => '<img src="' . e(url('assets/img/perfil/' . $n . '.svg')) . '" alt="" width="24" height="24" loading="lazy" class="shrink-0 ' . $cls . '">';
$card  = 'rounded-2xl bg-white border border-rose-100 p-5';
$lbl   = 'block text-sm font-semibold text-slate-700 mb-1';
$field = 'relative';
$fi    = 'absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none opacity-70';
$inp   = INPUT_CLS . ' pl-11 min-h-[44px]';
$link  = 'inline-flex items-center gap-1 min-h-[44px] font-semibold text-rose-700 underline underline-offset-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500';

page_start('Mi perfil', 'max-w-4xl');
?>
<section class="mt-2 mb-4 rounded-2xl bg-white border border-rose-100 p-5 grid md:grid-cols-[minmax(0,1fr)_auto] items-center gap-6">
  <div class="flex items-center gap-4 min-w-0">
    <div class="h-16 w-16 shrink-0 rounded-full bg-rose-600 text-white text-2xl font-bold flex items-center justify-center" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['email'], 0, 1))) ?></div>
    <div class="min-w-0">
      <h1 class="text-2xl font-bold">Mi perfil</h1>
      <p class="text-sm text-slate-600 break-all"><?= e((string) $user['email']) ?></p>
      <?php if ($since): ?><p class="text-sm text-slate-600 inline-flex items-center gap-1"><?= $ico('ico-calendar', 'h-4 w-4') ?>Miembro desde <?= e($since) ?></p><?php endif; ?>
      <p class="mt-1"><span class="inline-flex items-center gap-1 rounded-full bg-rose-50 border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-700"><?= $ico('ico-crown', 'h-4 w-4') ?><?= $tier ? 'Plan ' . e((string) $tier['name']) : 'Gratis' ?></span></p>
    </div>
  </div>
  <img src="<?= e(url('assets/img/perfil/hero-perfil.svg')) ?>" alt="" width="320" height="200" loading="lazy" class="hidden md:block justify-self-end w-36 lg:w-64 h-auto">
</section>

<div class="grid md:grid-cols-2 gap-4 items-start">
  <div class="space-y-4">
    <section class="<?= $card ?>" aria-labelledby="h-cuenta">
      <h2 id="h-cuenta" class="font-semibold mb-3 flex items-center gap-2"><?= $ico('ico-mail') ?>Cuenta</h2>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="email">
        <div>
          <label class="<?= $lbl ?>" for="email">Correo electrónico</label>
          <div class="<?= $field ?>"><?= $ico('ico-mail', $fi) ?>
            <input id="email" class="<?= $inp ?>" type="email" name="email" required maxlength="254" autocomplete="email"
                   <?= $errors['email'] ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>
                   value="<?= e($errors['email'] !== null ? (string) ($_POST['email'] ?? '') : (string) $user['email']) ?>"></div>
          <?php if ($errors['email']): ?><p id="email-err" role="alert" class="mt-1 text-sm text-rose-700"><?= e($errors['email']) ?></p><?php endif; ?>
        </div>
        <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2">Guardar correo</button>
      </form>
    </section>

    <section class="<?= $card ?>" aria-labelledby="h-seg">
      <h2 id="h-seg" class="font-semibold mb-3 flex items-center gap-2"><?= $ico('ico-shield') ?>Seguridad</h2>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <?php $pe = $errors['password'] ? 'aria-invalid="true" aria-describedby="pw-err"' : ''; ?>
        <div>
          <label class="<?= $lbl ?>" for="current_password">Contraseña actual</label>
          <div class="<?= $field ?>"><?= $ico('ico-lock', $fi) ?><input id="current_password" class="<?= $inp ?>" type="password" name="current_password" required maxlength="72" autocomplete="current-password" <?= $pe ?>></div>
        </div>
        <div>
          <label class="<?= $lbl ?>" for="new_password">Contraseña nueva</label>
          <div class="<?= $field ?>"><?= $ico('ico-key', $fi) ?><input id="new_password" class="<?= $inp ?>" type="password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="pw-help<?= $errors['password'] ? ' pw-err' : '' ?>" <?= $errors['password'] ? 'aria-invalid="true"' : '' ?>></div>
          <p id="pw-help" class="mt-1 text-xs text-slate-600">Mínimo 8 caracteres</p>
        </div>
        <div>
          <label class="<?= $lbl ?>" for="confirm_password">Repite la contraseña nueva</label>
          <div class="<?= $field ?>"><?= $ico('ico-key', $fi) ?><input id="confirm_password" class="<?= $inp ?>" type="password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password" <?= $pe ?>></div>
        </div>
        <?php if ($errors['password']): ?><p id="pw-err" role="alert" class="text-sm text-rose-700"><?= e($errors['password']) ?></p><?php endif; ?>
        <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2">Cambiar contraseña</button>
      </form>
    </section>
  </div>

  <div class="space-y-4">
    <section class="<?= $card ?>" aria-labelledby="h-plan">
      <h2 id="h-plan" class="font-semibold mb-3 flex items-center gap-2"><?= $ico('ico-crown') ?>Tu plan</h2>
      <p class="text-sm font-semibold"><?= $tier ? 'Plan ' . e((string) $tier['name']) : 'Plan gratuito' ?></p>
      <?php $pExp = Access::membershipExpiresAt($user); $pDays = Access::membershipDaysLeft($user); ?>
      <?php if ($tier): ?>
        <p class="mt-1 text-sm <?= $pDays !== null && $pDays <= 7 ? 'font-semibold text-amber-800' : 'text-slate-600' ?>"><?= $pExp !== null ? 'Vence el ' . e(date('d/m/Y', (int) strtotime($pExp . ' UTC'))) . ($pDays !== null ? ' · quedan ' . $pDays . ($pDays === 1 ? ' día' : ' días') : '') : 'Sin vencimiento' ?></p>
      <?php elseif (Access::wasMember($user)): ?>
        <p role="status" class="mt-1 text-sm font-semibold text-amber-800">Tu plan venció. Renuévalo para recuperar tus beneficios.</p>
      <?php endif; ?>
      <p class="mt-2 text-sm <?= $pFull ? 'font-semibold text-rose-800' : 'text-slate-600' ?> flex items-center gap-2"><?= $ico('ico-pages', 'h-5 w-5') ?><?= $isMonth ? $pUsed . ' de ' . $pMax . ' páginas este mes' : $pUsed . ' de ' . $pMax . ' páginas activas' ?></p>
      <div class="mt-2 h-2 rounded-full <?= $pFull ? 'bg-rose-200' : 'bg-rose-100' ?> overflow-hidden" role="progressbar" aria-label="<?= $isMonth ? 'Páginas gratuitas usadas este mes' : 'Páginas activas' ?>" aria-valuemin="0" aria-valuemax="<?= $pMax ?>" aria-valuenow="<?= $pUsed ?>" aria-valuetext="<?= e($pUsed . ' de ' . $pMax) ?>"><div class="h-full <?= $pFull ? 'bg-rose-700' : 'bg-rose-500' ?>" style="width:<?= $pct ?>%"></div></div>
      <?php if ($isMonth): ?>
        <?php if ($pFull): ?><p role="status" class="mt-2 text-sm font-semibold text-rose-800">Ya usaste tus páginas gratuitas de este mes.</p><?php endif; ?>
        <p class="mt-1 text-xs text-slate-600">Se reinicia el <?= e(Access::monthResetLabel($usage['resets_at'])) ?> · cada página dura <?= (int) Access::siteDays($user) ?> días</p>
      <?php endif; ?>
      <ul class="mt-3 space-y-1 text-sm text-slate-700">
        <?php if ($tier): ?>
          <li class="flex items-center gap-2"><?= $ico('ico-check', 'h-5 w-5') ?><?= (int) $tier['template_discount_pct'] ?> % de descuento en plantillas extra</li>
          <?php if ((int) $tier['ad_free'] === 1): ?><li class="flex items-center gap-2"><?= $ico('ico-check', 'h-5 w-5') ?>Sin anuncios</li><?php endif; ?>
        <?php else: ?>
          <li class="text-slate-600">Con una membresía tienes más páginas, descuentos y sin anuncios.</li>
        <?php endif; ?>
      </ul>
      <p class="mt-3 text-sm flex items-center gap-2"><?= $ico('ico-coin', 'h-5 w-5') ?>Saldo: <strong><?= Coins::balance($uid) ?> monedas</strong></p>
      <div class="mt-2 flex flex-wrap gap-x-5">
        <a class="<?= $link ?>" href="<?= e(url('tienda.php#monedas')) ?>">Recargar monedas<?= $ico('ico-arrow-right', 'h-4 w-4') ?></a>
        <a class="<?= $link ?>" href="<?= e(url('tienda.php#membresias')) ?>"><?= $tier || Access::wasMember($user) ? 'Renovar' : 'Ver membresías' ?><?= $ico('ico-arrow-right', 'h-4 w-4') ?></a>
      </div>
    </section>

    <section class="<?= $card ?>" aria-labelledby="h-priv">
      <h2 id="h-priv" class="font-semibold mb-1 flex items-center gap-2"><?= $ico('ico-file-text') ?>Privacidad y datos</h2>
      <p class="text-sm text-slate-600">Consulta cómo cuidamos y usamos tus datos.</p>
      <a class="<?= $link ?>" href="<?= e(url('privacy.php')) ?>">Política de privacidad<?= $ico('ico-arrow-right', 'h-4 w-4') ?></a>
    </section>
  </div>
</div>
<?php page_end();
