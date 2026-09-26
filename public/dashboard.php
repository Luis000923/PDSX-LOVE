<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$pdo  = db();

// Renovar / borrar sitio (POST + CSRF + comprobación de propietario en la propia consulta).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    if (isset($_POST['checkin'])) {
        $r = Checkin::claim((int) $user['id']);
        flash($r['ok'] ? '¡Check-in listo! +' . $r['coins'] . ' 🪙' : (string) $r['error']);
        redirect('dashboard.php');
    }
    if (isset($_POST['open_chest'])) {
        $r = Chests::open((int) $user['id'], (int) $_POST['open_chest']);
        flash($r['ok'] ? '🎁 ¡Cofre abierto! +' . $r['coins'] . ' 🪙 por tu fidelidad.' : (string) $r['error']);
        redirect('dashboard.php');
    }
    if (isset($_POST['renew'])) {
        $err = Sites::renew($user, (int) $_POST['renew']);
        flash($err ?? 'Página renovada.');
        redirect('dashboard.php');
    }
    $delId = (int) ($_POST['delete'] ?? 0);
    $slugSt = $pdo->prepare('SELECT slug FROM user_sites WHERE id = ? AND user_id = ?');
    $slugSt->execute([$delId, $user['id']]);
    $delSlug = $slugSt->fetchColumn();
    $pdo->prepare('DELETE FROM user_sites WHERE id = ? AND user_id = ?')
        ->execute([$delId, $user['id']]);
    if (is_string($delSlug)) {
        Sites::purgeFiles($delSlug);   // fotos y HTML propio
    }
    flash('Página eliminada.');
    redirect('dashboard.php');
}

$chests = Chests::available((int) $user['id']);
$canCheckin = Checkin::canClaim((int) $user['id']);
$refCode = Referrals::codeFor((int) $user['id']);
$refStats = Referrals::stats((int) $user['id']);
$refLink = url('register.php?ref=' . $refCode);
$st = $pdo->prepare('SELECT s.id, s.slug, s.data, s.expires_at, t.name, t.kind, t.price_coins, t.membership_unlocks FROM user_sites s JOIN templates t ON t.id = s.template_id WHERE s.user_id = ? ORDER BY s.id DESC');
$st->execute([$user['id']]);
$sites = $st->fetchAll();

page_start('Mis páginas', 'max-w-4xl');
$tier = Access::userTier($user);
$allowed = Access::siteAllowance($user);
$active = Access::siteCount((int) $user['id']);
$usage = Access::siteUsage($user);
$isMonth = $usage['mode'] === 'month';
$pUsed = $isMonth ? $usage['used'] : $active;
$pMax = $isMonth ? $usage['allowed'] : $allowed;
$pct = $pMax > 0 ? min(100, (int) round($pUsed / $pMax * 100)) : 0;
$tiers = Access::tiers();
$canUpgrade = $tiers !== [] && (int) ($tier['sort_order'] ?? 0) < (int) $tiers[count($tiers) - 1]['sort_order'];
$planExpires = Access::membershipExpiresAt($user);
$planDays = Access::membershipDaysLeft($user);
$planExpired = Access::wasMember($user);
$planSoon = $tier !== null && $planDays !== null && $planDays <= 7;
$planExpiryTxt = $planExpires !== null ? 'vence el ' . date('d/m/Y', (int) strtotime($planExpires . ' UTC')) . ($planDays !== null ? ' · quedan ' . $planDays . ($planDays === 1 ? ' día' : ' días') : '') : '';
$ico = static fn (string $n, string $cls = ''): string => '<img src="' . e(url('assets/img/paginas/' . $n . '.svg')) . '" alt="" width="24" height="24" class="shrink-0 ' . $cls . '">';
$act = 'inline-flex items-center justify-center gap-2 min-h-[44px] px-3 rounded-xl border border-rose-200 bg-white text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500';
$off = 'inline-flex items-center justify-center gap-2 min-h-[44px] px-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-500 opacity-60 cursor-not-allowed';
?>
<section class="mt-2 rounded-2xl bg-white border border-rose-100 p-5 flex items-center gap-6">
  <div class="flex-1 min-w-0">
    <h1 class="text-2xl font-bold">Mis páginas</h1>
    <a class="mt-3 inline-flex items-center justify-center min-h-[44px] px-6 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm" href="<?= e(url('upload_html.php')) ?>">Subir mi plantilla</a>
    <p class="mt-1 text-sm text-slate-600"><?= $tier ? 'Plan ' . e((string) $tier['name']) : 'Plan gratuito' ?> · <?= $isMonth ? $pUsed . ' de ' . $pMax . ' este mes' : $pUsed . '/' . $pMax . ' activas' ?></p>
    <?php if ($isMonth && $usage['remaining'] <= 0): ?><p role="status" class="mt-1 text-sm font-semibold text-rose-800">Usaste tus <?= $pMax ?> páginas gratuitas de este mes. Se reinician el <?= e(Access::monthResetLabel($usage['resets_at'])) ?>.</p><?php endif; ?>
    <?php if ($tier && $planExpiryTxt !== ''): ?><p class="mt-1 text-sm <?= $planSoon ? 'font-semibold text-amber-800' : 'text-slate-600' ?>">Tu plan <?= e($planExpiryTxt) ?></p><?php endif; ?>
    <div class="mt-2 h-2 rounded-full bg-rose-100 overflow-hidden" role="progressbar" aria-label="<?= $isMonth ? 'Páginas gratuitas usadas este mes' : 'Páginas activas' ?>" aria-valuemin="0" aria-valuemax="<?= $pMax ?>" aria-valuenow="<?= $pUsed ?>"><div class="h-full bg-rose-500" style="width:<?= $pct ?>%"></div></div>
    <p class="mt-3 text-sm"><a class="inline-flex items-center min-h-[44px] font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('tienda.php#monedas')) ?>">Saldo: <?= Coins::balance((int) $user['id']) ?> 🪙 · Recargar</a></p>
  </div>
  <img class="hidden md:block" src="<?= e(url('assets/img/paginas/hero-paginas.svg')) ?>" alt="" width="320" height="200" loading="lazy">
</section>

<?php foreach ($chests as $c): ?>
<form method="post" role="status" class="mt-4 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><?= csrf_field() ?>
  <span>🎁 <strong>¡Cofre de aniversario disponible!</strong> Tu página cumplió <?= e(Chests::label((string) $c['milestone'])) ?>: ábrelo y llévate <?= (int) $c['coins'] ?> 🪙.</span>
  <button name="open_chest" value="<?= (int) $c['id'] ?>" class="shrink-0 min-h-[44px] px-4 rounded-xl bg-amber-600 text-white font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">Abrir</button>
</form>
<?php endforeach; ?>
<section class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
  <form method="post" class="rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm flex items-center justify-between gap-3"><?= csrf_field() ?>
    <span><strong>Check-in diario</strong><br><span class="text-slate-600"><?= $canCheckin ? 'Reclama ' . Checkin::coins() . ' 🪙 gratis hoy.' : 'Ya reclamaste hoy. Vuelve mañana.' ?></span></span>
    <button name="checkin" value="1" <?= $canCheckin ? '' : 'disabled aria-disabled="true"' ?> class="shrink-0 min-h-[44px] px-4 rounded-xl font-semibold <?= $canCheckin ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-500 cursor-not-allowed' ?> focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">Reclamar</button>
  </form>
  <div class="rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm">
    <strong>Invita y gana</strong> · <?= Referrals::pct() ?> % en monedas cuando tu invitado haga su primera compra.
    <p class="mt-1 text-slate-600 break-all"><button type="button" class="font-semibold text-rose-700 underline min-h-[44px]" data-copy="<?= e($refLink) ?>"><span data-copy-label aria-live="polite">Copiar mi enlace</span></button> · <?= $refStats['invited'] ?> invitados · <?= $refStats['coins'] ?> 🪙 ganadas</p>
  </div>
</section>

<div class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-center">
  <a href="<?= e(url('create.php')) ?>" class="text-center sm:px-6 <?= BTN_CLS ?> sm:w-auto"><?= $ico('ico-plus', 'inline -mt-1 mr-1 brightness-0 invert') ?>Nueva página</a>
  <a href="<?= e(url('index.php')) ?>" class="inline-flex items-center justify-center min-h-[44px] text-sm font-semibold text-rose-700 underline underline-offset-2">Explorar la galería</a>
</div>

<?php if ($planExpired && $tier === null): ?>
<p role="status" class="mt-4 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Tu plan venció. Tus páginas ya creadas siguen activas hasta su propia fecha, pero solo puedes crear las del plan gratuito.</p>
<?php endif; ?>
<?php if ($canUpgrade || $planSoon || $planExpired): ?>
<a href="<?= e(url('tienda.php#membresias')) ?>" class="mt-4 flex items-center justify-between gap-3 min-h-[44px] rounded-xl bg-rose-100/60 border border-rose-100 px-4 py-2 text-sm text-rose-800 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
  <span><?php if ($planSoon || $planExpired): ?><strong>Renueva o mejora tu plan</strong> · conserva tus páginas extra y sin anuncios<?php else: ?><strong>Sube de plan</strong> · más páginas y sin anuncios<?php endif; ?></span><span aria-hidden="true">→</span>
</a>
<?php endif; ?>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($sites as $s):
      $d = json_decode($s['data'], true) ?: [];
      $link = url('c/' . $s['slug']);
      $exp = $s['expires_at'] === null ? null : (string) $s['expires_at'];
      $expired = Access::isExpired($exp);
      $ts = $exp === null ? null : (int) strtotime($exp . ' UTC');
      $left = $ts === null ? null : max(0, (int) ceil(($ts - time()) / 86400));
      $cost = Access::coinCost($user, $s);
      if ($expired) { $badge = ['Expirada', 'bg-rose-100 text-rose-800']; }
      elseif ($left !== null && $left <= 7) { $badge = ['Expira en ' . $left . ($left === 1 ? ' día' : ' días'), 'bg-amber-100 text-amber-900']; }
      else { $badge = ['Activa', 'bg-emerald-100 text-emerald-800']; } ?>
    <article class="rounded-2xl bg-white border border-rose-100 p-4 flex flex-col gap-3 <?= $expired ? 'bg-slate-50' : '' ?>">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <h2 class="font-semibold truncate"><?= e($d['your_name'] ?? '') ?><?= ($d['partner_name'] ?? '') !== '' ? ' ♥ ' . e($d['partner_name']) : '' ?></h2>
          <p class="text-xs text-slate-600">Plantilla <?= e($s['name']) ?></p>
        </div>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold <?= $badge[1] ?>"><?= e($badge[0]) ?></span>
      </div>
      <p class="text-xs text-slate-600 flex items-center gap-1.5"><?= $ico('ico-calendar', 'w-4 h-4') ?>
        <?php if ($ts === null): ?>Sin fecha de vencimiento
        <?php else: ?><?= $expired ? 'Venció el ' : 'Vence el ' ?><?= e(date('d/m/Y', $ts)) ?><?= $expired ? '' : ' · quedan ' . $left . ($left === 1 ? ' día' : ' días') ?><?php endif; ?></p>
      <?php if ($expired): ?>
        <p class="text-sm text-slate-500 truncate" title="<?= e($link) ?>"><?= e($link) ?></p>
      <?php else: ?>
        <a class="block text-sm text-rose-700 underline truncate" href="<?= e($link) ?>" target="_blank" rel="noopener" title="<?= e($link) ?>"><?= e($link) ?></a>
      <?php endif; ?>
      <div class="grid grid-cols-2 gap-2">
        <?php if ($expired): ?>
          <span class="<?= $off ?>" aria-disabled="true"><?= $ico('ico-copy') ?>Copiar enlace</span>
          <span class="<?= $off ?>" aria-disabled="true"><?= $ico('ico-download') ?>Descargar HTML</span>
        <?php else: ?>
          <button type="button" class="<?= $act ?>" data-copy="<?= e($link) ?>"><?= $ico('ico-copy') ?><span data-copy-label aria-live="polite">Copiar enlace</span></button>
          <a class="<?= $act ?>" href="<?= e(url('download.php?id=' . (int) $s['id'])) ?>"><?= $ico('ico-download') ?>Descargar HTML</a>
          <a class="<?= $act ?>" href="<?= e($link) ?>" target="_blank" rel="noopener"><?= $ico('ico-external') ?>Abrir<span class="sr-only"> (nueva pestaña)</span></a>
          <?php if ($s['kind'] === 'user'): ?><a class="<?= $act ?> col-span-2" href="<?= e(url('upload_html.php?modo=publica&desde=' . (int) $s['id'])) ?>">Publicar en la Galería</a><?php endif; ?>
        <?php endif; ?>
        <?php if ($expired): ?>
          <form method="post" class="contents"><?= csrf_field() ?>
            <button name="renew" value="<?= (int) $s['id'] ?>" class="col-span-2 <?= BTN_CLS ?>"><?= $ico('ico-renew', 'inline -mt-1 mr-1 brightness-0 invert') ?>Renovar<?= $cost > 0 ? ' por ' . $cost . ' 🪙' : ' gratis' ?></button>
          </form>
          <?php if ($isMonth && $cost <= 0): ?><p class="col-span-2 text-xs text-slate-600">Renovar usa una de tus páginas gratuitas de este mes.</p><?php endif; ?>
        <?php endif; ?>
      </div>
      <details class="text-sm">
        <summary class="inline-flex items-center gap-1 min-h-[44px] cursor-pointer text-slate-500 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 rounded"><?= $ico('ico-trash-muted', 'w-5 h-5') ?>Eliminar</summary>
        <form method="post" class="mt-1 flex items-center gap-3 flex-wrap"><?= csrf_field() ?>
          <span class="text-slate-600">Se borrará para siempre.</span>
          <button name="delete" value="<?= (int) $s['id'] ?>" class="min-h-[44px] px-4 rounded-xl bg-rose-700 text-white font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">Confirmar</button>
        </form>
      </details>
    </article>
  <?php endforeach; ?>
  <?php if (!$sites): ?><?= empty_state('pages', 'Aún no tienes páginas', 'Elige una plantilla en la galería y crea tu primera página de pareja.', url('index.php'), 'Elegir una plantilla') ?><?php endif; ?>
</div>
<script src="<?= e(url('assets/js/copy.js')) ?>" defer nonce="<?= e(csp_nonce()) ?>"></script>
<?php page_end();
