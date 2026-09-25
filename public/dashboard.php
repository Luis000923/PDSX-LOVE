<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$pdo  = db();

// Borrado de sitio (POST + CSRF + comprobación de propietario en la propia consulta).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $pdo->prepare('DELETE FROM user_sites WHERE id = ? AND user_id = ?')
        ->execute([(int) ($_POST['delete'] ?? 0), $user['id']]);
    flash('Página eliminada.');
    redirect('dashboard.php');
}

$st = $pdo->prepare('SELECT s.id, s.slug, s.data, t.name FROM user_sites s JOIN templates t ON t.id = s.template_id WHERE s.user_id = ? ORDER BY s.id DESC');
$st->execute([$user['id']]);
$sites = $st->fetchAll();

// Estado del último pago (la activación real ocurre solo vía webhook firmado).
$st = $pdo->prepare('SELECT status FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$user['id']]);
$lastPayment = $st->fetchColumn();
$price = number_format(wompi_config()['price_in_cents'] / 100, 0, ',', '.');

page_start('Mis páginas');
?>
<h1 class="text-2xl font-bold mt-4">Mis páginas</h1>

<?php if (!$user['is_premium']): ?>
  <section class="mt-4 rounded-2xl bg-white border border-amber-200 p-4">
    <p class="font-semibold">Pásate a Premium ✨</p>
    <p class="text-sm text-slate-600 mt-1">Plantillas exclusivas, efectos y sin anuncios. Pago único de $<?= e($price) ?> COP.</p>
    <?php if ($lastPayment === 'PENDING'): ?>
      <p class="text-sm text-amber-700 mt-2">Estamos confirmando tu pago… recarga en unos segundos.</p>
    <?php elseif (in_array($lastPayment, ['DECLINED', 'ERROR', 'VOIDED'], true)): ?>
      <p class="text-sm text-rose-700 mt-2">Tu último pago no fue aprobado. Puedes reintentar.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('checkout_wompi.php')) ?>" class="mt-3 space-y-2">
      <?= csrf_field() ?>
      <label class="sr-only" for="promo">Código de promoción</label>
      <input id="promo" name="promo" class="<?= INPUT_CLS ?>" maxlength="32" placeholder="Código de promoción (opcional)"
             pattern="[A-Za-z0-9-]{3,32}" autocomplete="off">
      <button class="<?= BTN_CLS ?>">Pagar con Wompi</button>
    </form>
  </section>
<?php else: ?>
  <p class="mt-2 text-sm text-emerald-600 font-semibold">✓ Cuenta Premium</p>
<?php endif; ?>

<div class="mt-6 space-y-3">
  <?php foreach ($sites as $s):
      $d = json_decode($s['data'], true) ?: [];
      $link = url('c/' . $s['slug']); ?>
    <article class="rounded-2xl bg-white border border-rose-100 p-4">
      <p class="font-semibold"><?= e($d['your_name'] ?? '') ?> ♥ <?= e($d['partner_name'] ?? '') ?></p>
      <p class="text-xs text-slate-500"><?= e($s['name']) ?></p>
      <a class="block mt-2 text-sm text-rose-600 break-all" href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($link) ?></a>
      <form method="post" class="mt-2">
        <?= csrf_field() ?><button name="delete" value="<?= (int) $s['id'] ?>" class="text-xs text-slate-400">Eliminar</button>
      </form>
    </article>
  <?php endforeach; ?>
  <?php if (!$sites): ?><p class="text-slate-500 text-sm">Aún no tienes páginas.</p><?php endif; ?>
</div>
<a href="<?= e(url('create.php')) ?>" class="mt-6 block text-center <?= BTN_CLS ?>">+ Nueva página</a>
<?php page_end();
