<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Mis plantillas públicas: estado, usos, ganancias; retirar y reenviar con archivo nuevo. */
$user = require_login();
$uid  = (int) $user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_verify();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $tid = (int) ($_POST['id'] ?? 0);
    if ($action === 'withdraw') {
        flash(Creators::withdraw($tid, $uid) ? 'Plantilla retirada del catálogo. Las páginas ya creadas siguen activas.' : 'No se pudo retirar esa plantilla.');
        redirect('creator.php');
    } elseif ($action === 'resubmit') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Elige el archivo .html nuevo.';
        } else {
            $r = Creators::inspectUpload($file['tmp_name'], (string) ($file['name'] ?? ''));
            $errors = $r['errors'] ?: Creators::resubmit($uid, $tid, $r['html']);
        }
        if (!$errors) {
            flash('Reenviada. Volverá a revisión.');
            redirect('creator.php');
        }
    } else {
        http_response_code(400);
        exit('Acción desconocida.');
    }
}

CreatorEarnings::settle($uid);   // perezoso: paga lo pendiente
$rows = Creators::mine($uid);
$tot = CreatorEarnings::totals($uid);
$cfg = Creators::config();
$badges = Awards::userBadges($uid);
$states = ['pending' => ['En revisión', 'bg-amber-100 text-amber-800'], 'approved' => ['Publicada', 'bg-emerald-100 text-emerald-800'],
           'rejected' => ['Rechazada', 'bg-rose-100 text-rose-800'], 'withdrawn' => ['Retirada', 'bg-slate-100 text-slate-700']];
$card = 'rounded-2xl bg-white border border-rose-100 p-5';

page_start('Mis plantillas públicas', 'max-w-4xl');
?>
<section class="mt-2 mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold">Mis plantillas públicas</h1>
    <p class="text-sm text-slate-600">Ganas <?= (int) $cfg['share_pct'] ?> % en monedas por cada uso de otra persona. Las monedas ganadas solo sirven dentro de LovePages.</p>
  </div>
  <a href="<?= e(url('creator_upload.php')) ?>" class="inline-flex items-center justify-center min-h-[44px] px-5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm">Publicar en la Galería</a>
</section>

<?php if ($errors): ?><div role="alert" class="mb-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm px-4 py-3"><ul class="list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="grid grid-cols-2 gap-3 mb-4" aria-label="Ganancias">
  <div class="<?= $card ?>"><p class="text-xs text-slate-500">Ganancias pendientes</p><p class="text-2xl font-bold"><?= (int) $tot['pending'] ?> <span class="text-sm font-normal text-slate-500">monedas</span></p></div>
  <div class="<?= $card ?>"><p class="text-xs text-slate-500">Ganancias pagadas</p><p class="text-2xl font-bold"><?= (int) $tot['paid'] ?> <span class="text-sm font-normal text-slate-500">monedas</span></p></div>
</section>
<?php if ($badges): ?><p class="mb-4 flex flex-wrap gap-2"><?php foreach (array_slice($badges, 0, 8) as $b): ?><span class="inline-flex items-center gap-1 rounded-full bg-white border border-rose-100 pl-1 pr-3 py-1 text-xs font-semibold"><?= Awards::badgeImg($b['key'], 24) ?><?= e(Awards::BADGES[$b['key']][0]) ?></span><?php endforeach; ?></p><?php endif; ?>

<section class="space-y-3" aria-label="Plantillas">
  <?php if (!$rows): ?>
    <?= empty_state('search', 'Aún no publicas plantillas', 'Envía tu primera plantilla y gana monedas cuando otras personas la usen.', url('creator_upload.php'), 'Publicar en la Galería') ?>
  <?php endif; ?>
  <?php foreach ($rows as $r): [$stLabel, $stCls] = $states[$r['review_status']] ?? ['—', '']; ?>
    <article class="<?= $card ?> flex flex-col sm:flex-row gap-4">
      <img src="<?= e($r['thumbnail'] ? url('assets/thumbs/' . $r['thumbnail']) : url('assets/img/paginas/thumb-fallback.svg')) ?>" alt="" width="160" height="96" loading="lazy" class="w-full sm:w-40 aspect-[5/3] object-cover rounded-xl">
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold break-words"><?= e((string) $r['name']) ?></h2><span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?= $stCls ?>"><?= e($stLabel) ?></span></div>
        <p class="text-xs text-slate-500"><?= e(Template::CATEGORIES[$r['category']] ?? '') ?> · <?= (int) $r['price_coins'] ?> monedas<?= (int) $r['membership_unlocks'] === 1 ? ' · con cupo de membresía' : '' ?></p>
        <p class="mt-1 text-sm text-slate-700"><?= (int) $r['uses'] ?> uso<?= (int) $r['uses'] === 1 ? '' : 's' ?> por otras personas · ganancias: <strong><?= (int) $r['pending_coins'] ?></strong> pendientes, <strong><?= (int) $r['paid_coins'] ?></strong> pagadas</p>
        <?php if ($r['review_status'] === 'rejected' && $r['review_note']): ?><p role="status" class="mt-2 text-sm text-rose-800">Nota de revisión: <?= e((string) $r['review_note']) ?></p><?php endif; ?>
        <div class="mt-3 flex flex-wrap gap-2 items-center">
          <?php if (in_array($r['review_status'], ['approved', 'pending'], true)): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="min-h-[44px] px-4 rounded-xl border border-rose-300 text-rose-700 text-sm font-semibold hover:bg-rose-50">Retirar del catálogo</button></form>
          <?php endif; ?>
          <?php if (in_array($r['review_status'], ['rejected', 'withdrawn'], true)): ?>
            <form method="post" enctype="multipart/form-data" class="flex flex-wrap gap-2 items-center"><?= csrf_field() ?><input type="hidden" name="action" value="resubmit"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <label class="sr-only" for="f<?= (int) $r['id'] ?>">Archivo .html nuevo</label>
              <input id="f<?= (int) $r['id'] ?>" name="file" type="file" required accept=".html,.htm,text/html" class="text-sm max-w-full">
              <button class="min-h-[44px] px-4 rounded-xl bg-rose-600 text-white text-sm font-semibold">Reenviar con archivo nuevo</button></form>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php page_end();
