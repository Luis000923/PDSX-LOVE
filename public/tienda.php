<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Tienda: membresías y recarga de monedas en una sola sección. Pública para ver precios;
 * comprar exige sesión. Los montos, monedas y bonos los calcula siempre el servidor.
 *
 * Bono de una recarga = % del paquete (Coins::PACK_BONUS_PCT) + % de la membresía.
 */
$user = current_user();
$uid  = $user !== null ? (int) $user['id'] : 0;
$tier = $user !== null ? Access::userTier($user) : null;

$lastMembership = null;
$lastCoins = null;
if ($user !== null) {
    $st = db()->prepare('SELECT status FROM payments WHERE user_id = ? AND coins IS NULL AND template_id IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$uid]);
    $lastMembership = $st->fetchColumn() ?: null;
    $st = db()->prepare('SELECT status FROM payments WHERE user_id = ? AND coins IS NOT NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$uid]);
    $lastCoins = $st->fetchColumn() ?: null;
}

$ico = static fn (string $n, int $s = 20, string $cls = ''): string =>
    '<img src="' . e(url('assets/img/tienda/' . $n . '.svg')) . '" alt="" width="' . $s . '" height="' . $s . '" loading="lazy" class="shrink-0 ' . $cls . '">';
$tierPct = $tier !== null ? (int) $tier['topup_bonus_pct'] : 0;
$bestCents = Coins::PACKS_CENTS[0];
$bestRate = 0.0;
foreach (Coins::PACKS_CENTS as $c) {
    $r = Coins::packCoins($c, $tier) / ($c / 100);
    if ($r > $bestRate) { $bestRate = $r; $bestCents = $c; }
}
$navCls = 'flex-1 text-center rounded-full px-4 py-2.5 min-h-[44px] flex items-center justify-center text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600';

page_start('Tienda', 'max-w-5xl');
?>
<section class="mt-4 grid md:grid-cols-2 gap-6 items-center">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Tienda</h1>
    <p class="mt-2 text-slate-600">Membresías de 1 o 2 meses, sin renovación automática, y monedas que no vencen para crear y renovar tus páginas de amor.</p>
    <?php if ($user !== null): ?>
      <p class="mt-5 inline-flex items-center gap-2 rounded-full bg-white border border-rose-100 pl-3 pr-4 py-2 text-sm">
        <?= $ico('ico-coin', 24) ?><span class="text-slate-500">Tu saldo</span>
        <strong class="text-base"><?= Coins::balance($uid) ?> monedas</strong>
      </p>
    <?php endif; ?>
  </div>
  <img src="<?= e(url('assets/img/tienda/hero.svg')) ?>" alt="" width="320" height="200" loading="lazy" class="hidden md:block justify-self-end w-full max-w-xs h-auto">
</section>

<nav class="sticky top-0 z-10 mt-6 -mx-1 px-1 py-2 bg-rose-50/90 backdrop-blur" aria-label="Secciones de la tienda">
  <div class="flex gap-1 rounded-full bg-white border border-rose-100 p-1">
    <a class="<?= $navCls ?>" href="#membresias">Membresías</a>
    <a class="<?= $navCls ?>" href="#monedas">Monedas</a>
  </div>
</nav>

<div id="membresias" class="scroll-mt-20">
  <?php premium_offer($user, $lastMembership, ($_GET['offer'] ?? '') === 'code'); ?>
</div>

<section id="monedas" class="mt-14 scroll-mt-20">
  <h2 class="text-xl font-bold">Recarga de monedas</h2>
  <p class="mt-1 text-sm text-slate-600">1 dólar = 10 monedas. Pago único, sin suscripción. Cuanto más recargas, mayor el bono.
    <?php if ($tierPct > 0): ?>Tu plan <strong><?= e((string) $tier['name']) ?></strong> suma <strong>+<?= $tierPct ?> %</strong> a cada paquete.
    <?php else: ?>Con Pareja o Eterno se suma un % extra a cada paquete.<?php endif; ?></p>
  <?php if ($lastCoins === 'PENDING'): ?><p role="status" class="mt-4 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Estamos confirmando tu recarga… actualiza en unos segundos.</p><?php endif; ?>

  <?php if ($user !== null): ?>
    <div class="mt-5 max-w-sm">
      <label for="coin-promo" class="block text-sm font-semibold text-slate-700">¿Tienes un cupón?</label>
      <input id="coin-promo" type="text" maxlength="32" autocomplete="off" autocapitalize="characters" placeholder="Código de promoción"
             class="mt-1 w-full min-h-[44px] rounded-xl border border-rose-200 bg-white px-4 text-base uppercase focus:outline-none focus:ring-2 focus:ring-rose-400">
      <p class="mt-1 text-xs text-slate-500">Rebaja el precio del paquete; las monedas que recibes no cambian.</p>
    </div>
  <?php endif; ?>
  <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
    <?php foreach (Coins::PACKS_CENTS as $cents):
        $base = Coins::packCoins($cents, null);
        $total = Coins::packCoins($cents, $tier);
        $packPct = Coins::packBonusPct($cents);
        $best = $cents === $bestCents; ?>
      <article class="relative rounded-2xl bg-white p-4 sm:p-5 flex flex-col items-center text-center <?= $best ? 'border-2 border-rose-600' : 'border border-rose-100' ?>">
        <?php if ($best): ?><span class="absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-rose-600 text-white text-xs font-semibold px-3 py-1">Mejor valor</span><?php endif; ?>
        <img src="<?= e(url('assets/img/tienda/pack-' . $cents . '.svg')) ?>" alt="" width="72" height="72" loading="lazy" class="h-[72px] w-[72px]">
        <p class="mt-3 text-2xl font-bold">$<?= e(wompi_format_usd($cents)) ?></p>
        <p class="mt-1 text-base font-semibold text-rose-600"><?= $total ?> monedas</p>
        <p class="mt-2 min-h-[24px]">
          <?php if ($packPct + $tierPct > 0): ?><span class="inline-block rounded-full bg-rose-50 text-rose-700 text-xs font-semibold px-2.5 py-0.5">+<?= $packPct + $tierPct ?> % bono</span>
          <?php else: ?><span class="text-xs text-slate-500">Base: <?= $base ?></span><?php endif; ?>
        </p>
        <p class="mt-1 text-xs text-slate-500"><?= number_format($total / ($cents / 100), 1) ?> monedas por dólar</p>
        <?php if ($user === null): ?>
          <a class="mt-4 block w-full text-center <?= BTN_CLS ?>" href="<?= e(url('register.php')) ?>">Crear cuenta</a>
        <?php else: ?>
          <form method="post" action="<?= e(url('checkout_wompi.php')) ?>" class="mt-4 w-full">
            <?= csrf_field() ?><input type="hidden" name="pack" value="<?= $cents ?>"><input type="hidden" name="promo" value="" data-coin-promo><button class="w-full <?= BTN_CLS ?>">Comprar</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <script nonce="<?= e(csp_nonce()) ?>">
  // El cupón se escribe una vez y viaja con el paquete que se compre (el servidor lo valida y calcula el descuento).
  (function () {
    var f = document.getElementById('coin-promo'); if (!f) return;
    document.querySelectorAll('#monedas form').forEach(function (form) {
      form.addEventListener('submit', function () { var h = form.querySelector('[data-coin-promo]'); if (h) h.value = f.value.trim(); });
    });
  })();
  </script>

  <ul class="mt-8 grid sm:grid-cols-3 gap-3 text-sm text-slate-600">
    <li class="flex items-center gap-2"><?= $ico('ico-shield', 24) ?>Pago seguro con Wompi</li>
    <li class="flex items-center gap-2"><?= $ico('ico-coin', 24) ?>Pago único, sin cargos ocultos</li>
    <li class="flex items-center gap-2"><?= $ico('ico-sparkle', 24) ?>Monedas acreditadas al confirmar el pago</li>
  </ul>

  <?php $hist = $user !== null ? Coins::history($uid, 10) : []; if ($hist):
      $labels = ['tier_bonus' => 'Bono de plan', 'topup' => 'Recarga', 'site_create' => 'Página creada', 'site_renew' => 'Página renovada'];
      $icons = ['tier_bonus' => 'ico-sparkle', 'topup' => 'ico-coin', 'site_create' => 'ico-pages', 'site_renew' => 'ico-clock']; ?>
    <h2 class="text-lg font-bold mt-12 mb-3">Últimos movimientos</h2>
    <ul class="text-sm divide-y divide-rose-100 bg-white rounded-2xl border border-rose-100">
      <?php foreach ($hist as $h): $d = (int) $h['delta']; ?>
        <li class="flex items-center gap-3 px-4 py-3">
          <?= $ico($icons[$h['reason']] ?? 'ico-coin', 24) ?>
          <span class="flex-1"><?= e($labels[$h['reason']] ?? (string) $h['reason']) ?></span>
          <span class="font-semibold <?= $d > 0 ? 'text-emerald-600' : 'text-slate-600' ?>"><?= $d > 0 ? '+' : '' ?><?= $d ?> monedas</span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php page_end();
