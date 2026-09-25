<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Galería de plantillas: búsqueda, filtro por categoría y orden (todo con valores de lista blanca + PDO). */
$cat  = is_string($_GET['cat'] ?? null) && array_key_exists($_GET['cat'], Template::CATEGORIES) ? $_GET['cat'] : '';
$sortKey = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], ['popular', 'new', 'price'], true) ? $_GET['sort'] : 'popular';
$order = ['popular' => 'uses DESC, t.id DESC', 'new' => 't.id DESC', 'price' => 't.price_usd ASC, t.is_premium ASC, t.id DESC'][$sortKey];
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 50) : '';
$onlyFree = ($_GET['free'] ?? '') === '1';

$sql = 'SELECT t.id, t.slug, t.name, t.description, t.thumbnail, t.kind, t.category, t.is_premium, t.price_usd, t.price_coins, t.membership_unlocks, t.credit_alias,
               (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id) AS uses
          FROM templates t WHERE ' . Creators::PUBLIC_WHERE . ($cat !== '' ? ' AND t.category = ?' : '')
          . ($q !== '' ? " AND (t.name LIKE ? ESCAPE '!' OR t.description LIKE ? ESCAPE '!')" : '') . " ORDER BY $order";
$params = $cat !== '' ? [$cat] : [];
if ($q !== '') {
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($params, $like, $like);
}
$st = db()->prepare($sql);
$st->execute($params);
$templates = $st->fetchAll();
if ($onlyFree) {   // gratis = sin precio en USD, sin membresía y sin costo en monedas
    $templates = array_values(array_filter($templates, static fn(array $t): bool => !Access::isPaid($t) && (int) ($t['price_coins'] ?? 0) === 0));
}

$user  = current_user();
$owned = $user ? Access::purchasedTemplateIds((int) $user['id']) : [];
$link  = static function (string $c, string $s, string $qq) use ($onlyFree): string {
    $qs = http_build_query(array_filter(['q' => $qq, 'cat' => $c, 'sort' => $s !== 'popular' ? $s : '', 'free' => $onlyFree ? '1' : '']));
    return e(url('index.php' . ($qs !== '' ? '?' . $qs : '')));
};
$ico   = static fn(string $n, int $s = 20, string $cls = ''): string =>
    '<img src="' . e(url('assets/img/paginas/' . $n . '.svg')) . '" alt="" width="' . $s . '" height="' . $s . '" loading="lazy" class="shrink-0 ' . $cls . '">';
$chip  = 'shrink-0 inline-flex items-center min-h-[44px] rounded-full px-4 text-sm font-semibold border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 ';
$on    = 'bg-rose-600 border-rose-600 text-white';
$off   = 'bg-white border-rose-200 text-slate-700 hover:border-rose-400';
$n     = count($templates);
$fromCents = Access::tiers() ? Access::tierPriceInCents(Access::tiers()[0]) : 100;

page_start('Galería de plantillas', 'max-w-5xl');
?>
<section class="mt-4 grid md:grid-cols-2 gap-6 items-center">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Galería de plantillas</h1>
    <p class="mt-1 text-lg font-semibold text-rose-600">Elige una plantilla y crea tu página de amor</p>
    <p class="mt-2 text-slate-600">Pruébala, personalízala y mándasela a esa persona especial.</p>
    <p class="mt-3 text-sm text-slate-500">¿Tienes tu propio diseño? <a class="inline-flex items-center justify-center min-h-[44px] px-6 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm" href="<?= e(url($user ? 'upload_html.php' : 'register.php')) ?>">Subir mi plantilla</a> <a class="ml-2 text-rose-700 underline font-semibold" href="<?= e(url('colaboradores.php')) ?>">Colaboradores destacados</a></p>
    <a href="#plantillas" class="mt-5 inline-flex items-center justify-center min-h-[44px] px-8 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2">Ver plantillas</a>
  </div>
  <img src="<?= e(url('assets/img/paginas/hero-galeria.svg')) ?>" alt="" width="320" height="200" loading="lazy" class="hidden md:block justify-self-end w-full max-w-xs h-auto">
</section>

<section id="plantillas" class="mt-8 scroll-mt-4 space-y-3" aria-label="Buscar y filtrar">
  <form method="get" action="<?= e(url('index.php')) ?>" class="flex gap-2" role="search">
    <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <?php if ($onlyFree): ?><input type="hidden" name="free" value="1"><?php endif; ?>
    <?php if ($sortKey !== 'popular'): ?><input type="hidden" name="sort" value="<?= e($sortKey) ?>"><?php endif; ?>
    <div class="relative flex-1">
      <label for="q" class="sr-only">Buscar plantillas</label>
      <span class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"><?= $ico('ico-search', 20) ?></span>
      <input id="q" name="q" type="search" value="<?= e($q) ?>" maxlength="50" placeholder="Buscar: aniversario, carta, contador…"
             class="w-full min-h-[44px] rounded-xl border border-rose-200 bg-white pl-10 pr-3 text-base focus:outline-none focus:ring-2 focus:ring-rose-400">
    </div>
    <button class="min-h-[44px] px-5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2">Buscar</button>
    <?php if ($q !== ''): ?><a href="<?= $link($cat, $sortKey, '') ?>" class="min-h-[44px] inline-flex items-center px-3 text-sm font-semibold text-rose-700 underline">Limpiar</a><?php endif; ?>
  </form>

  <nav class="-mx-5 px-5 flex gap-2 overflow-x-auto pb-1" aria-label="Categorías">
    <a href="<?= $link('', $sortKey, $q) ?>" class="<?= $chip . ($cat === '' ? $on : $off) ?>" <?= $cat === '' ? 'aria-current="true"' : '' ?>><?= $ico('ico-grid', 16, $cat === '' ? 'mr-1.5 brightness-0 invert' : 'mr-1.5') ?>Todas</a>
    <?php foreach (Template::CATEGORIES as $k => $label): ?>
      <a href="<?= $link($k, $sortKey, $q) ?>" class="<?= $chip . ($cat === $k ? $on : $off) ?>" <?= $cat === $k ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
    <?php $qsFree = http_build_query(array_filter(['q' => $q, 'cat' => $cat, 'sort' => $sortKey !== 'popular' ? $sortKey : '', 'free' => $onlyFree ? '' : '1'])); ?>
    <a href="<?= e(url('index.php' . ($qsFree !== '' ? '?' . $qsFree : ''))) ?>#plantillas" role="switch" aria-checked="<?= $onlyFree ? 'true' : 'false' ?>" class="<?= $chip . ($onlyFree ? $on : $off) ?>">Solo gratis</a>
    <p class="text-slate-500" aria-live="polite"><strong class="text-slate-800"><?= $n ?></strong> plantilla<?= $n === 1 ? '' : 's' ?></p>
    <nav class="flex items-center rounded-full bg-white border border-rose-100 p-0.5" aria-label="Ordenar por">
      <?php foreach (['popular' => 'Populares', 'new' => 'Nuevas', 'price' => 'Precio'] as $k => $label): ?>
        <a href="<?= $link($cat, $k, $q) ?>" class="min-h-[44px] inline-flex items-center px-3 rounded-full font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 <?= $sortKey === $k ? 'bg-rose-100 text-rose-700' : 'text-slate-600 hover:text-slate-900' ?>" <?= $sortKey === $k ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>

<section class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
  <?php foreach ($templates as $t):
      $cents = Access::priceInCents($t);
      $coins = max(0, (int) ($t['price_coins'] ?? 0));
      $paid  = Access::isPaid($t);
      $free  = !$paid && $coins === 0;
      // "Solo monedas": no es premium ni tiene precio en USD, pero cuesta monedas al usarla (nunca bloqueada).
      $coinOnly = !$paid && $coins > 0;
      // "Membresía con cupo": la membresía la cubre hasta agotar su cupo mensual; si no, se usa pagando monedas.
      $quota = $paid && (int) ($t['membership_unlocks'] ?? 0) === 1 && $coins > 0;
      $mine  = $user && Access::canUse($user, $t, $owned);
      $locked = !$free && !$coinOnly && !$quota && !$mine;
      $label = $free ? 'Gratis'
          : ($coinOnly ? $coins . ' monedas'
          : ($quota ? 'Membresía'
          : ($mine ? 'Desbloqueada' : ($cents > 0 ? '$' . wompi_format_usd($cents) : 'Membresía'))));
      $badge = $free || (!$quota && !$coinOnly && $mine) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
      $prev  = e(url('preview.php?t=' . rawurlencode((string) $t['slug'])));
      $embedPrev = e(url('preview.php?t=' . rawurlencode((string) $t['slug']) . '&embed=1')); ?>
    <article class="group rounded-2xl bg-white border border-rose-100 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5 transition flex flex-col">
      <div class="relative overflow-hidden bg-rose-50 aspect-[5/3]" data-media>
        <img src="<?= e(url($t['thumbnail'] ? 'assets/thumbs/' . $t['thumbnail'] : 'assets/img/paginas/thumb-fallback.svg')) ?>" alt="" width="400" height="240" loading="lazy" class="absolute inset-0 w-full h-full object-cover">
        <iframe data-frame data-src="<?= $embedPrev ?>" title="Preview de <?= e($t['name']) ?>" class="absolute top-0 left-0 border-0 bg-white opacity-0 transition-opacity duration-500 origin-top-left pointer-events-none" style="width:375px;height:225px" sandbox="allow-scripts" tabindex="-1" aria-hidden="true"></iframe>
        <a href="<?= $prev ?>" target="_blank" rel="noopener" class="absolute inset-0 z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rose-600" aria-label="Abrir vista previa de <?= e($t['name']) ?> (pestaña nueva)"></a>
        <?php if ($coins > 0): ?><span class="absolute bottom-3 left-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold pointer-events-none z-20 bg-white/95 text-amber-800 shadow-sm">🪙 <?= $coins ?> monedas</span><?php endif; ?>
        <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold pointer-events-none z-20 <?= $badge ?>"><?= $locked ? $ico('ico-lock', 12) : '' ?><?= e($label) ?></span>
        <?php if ($t['kind'] === 'php'): ?><span class="absolute top-3 right-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold pointer-events-none z-20 bg-indigo-100 text-indigo-800"><?= $ico('badge-interactiva', 16) ?>Interactiva</span><?php endif; ?>
      </div>
      <div class="p-4 flex flex-col flex-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-rose-600"><?= e(Template::CATEGORIES[$t['category']] ?? '') ?></p>
        <h2 class="mt-0.5 font-semibold text-lg leading-snug"><?= e($t['name']) ?></h2>
        <?php if ($t['kind'] === 'utpl' && $t['credit_alias']): ?><p class="text-xs text-slate-500">Por <?= e((string) $t['credit_alias']) ?></p><?php endif; ?>
        <?php if ($t['description']): ?><p class="mt-1 text-sm text-slate-600 line-clamp-2"><?= e($t['description']) ?></p><?php endif; ?>
        <?php // Precio siempre visible, también en las plantillas de membresía (con su valor en monedas). ?>
        <p class="mt-2 text-sm font-semibold text-slate-900">
          <?php if ($free): ?>Gratis
          <?php elseif ($coinOnly): ?><?= $coins ?> monedas
          <?php elseif ($quota): ?><?= $coins ?> monedas <span class="font-normal text-slate-500">· gratis con el cupo de tu membresía</span>
          <?php elseif ($cents > 0): ?>$<?= e(wompi_format_usd($cents)) ?> USD <span class="font-normal text-slate-500">· <?= $coins > 0 ? $coins . ' monedas · ' : '' ?>o con membresía</span>
          <?php else: ?>Con membresía<?php if ($coins > 0): ?> <span class="font-normal text-slate-500">· <?= $coins ?> monedas</span><?php endif; ?><?php endif; ?>
        </p>
        <p class="mt-1 text-xs text-slate-500"><?= (int) $t['uses'] ?> página<?= (int) $t['uses'] === 1 ? '' : 's' ?> creada<?= (int) $t['uses'] === 1 ? '' : 's' ?></p>
        <div class="mt-auto pt-4 grid grid-cols-2 gap-2">
          <a href="<?= $prev ?>" target="_blank" rel="noopener"
             class="inline-flex items-center justify-center gap-1.5 min-h-[44px] rounded-xl border border-rose-300 text-rose-700 font-semibold text-sm hover:bg-rose-50 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600"><?= $ico('ico-eye', 18) ?>Vista previa</a>
          <a href="<?= e(url($user ? 'create.php?template=' . rawurlencode((string) $t['slug']) : 'register.php')) ?>"
             class="inline-flex items-center justify-center gap-1.5 min-h-[44px] rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"><?= $locked && $user ? $ico('ico-lock', 16, 'brightness-0 invert') . 'Desbloquear' : 'Usar' ?></a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$templates): ?><?= empty_state('search', 'No encontramos plantillas', 'Prueba con otra palabra o quita los filtros.', ($q !== '' || $cat !== '') ? url('index.php') : null, 'Quitar filtros') ?><?php endif; ?>
</section>

<?php if (!$user || Access::userTier($user) === null): ?>
  <aside class="mt-12 rounded-2xl bg-white border border-rose-100 p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h2 class="font-bold text-lg">Más páginas, sin anuncios</h2>
      <p class="text-sm text-slate-600">Membresías desde $<?= e(wompi_format_usd($fromCents)) ?> USD · 1 mes.</p>
    </div>
    <a href="<?= e(url('tienda.php#membresias')) ?>" class="inline-flex items-center justify-center min-h-[44px] px-6 rounded-xl border border-rose-300 text-rose-700 font-semibold text-sm hover:bg-rose-50 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600">Ver membresías</a>
  </aside>
<?php endif; ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var frames = document.querySelectorAll('[data-frame]');
  function fit(f) { var w = f.parentElement.clientWidth; if (!w) return; var k = w / 375; f.style.height = (f.parentElement.clientHeight / k) + 'px'; f.style.transform = 'scale(' + k + ')'; }
  function load(f) { fit(f); if (!f.src) { f.addEventListener('load', function () { f.classList.remove('opacity-0'); }, { once: true }); f.src = f.dataset.src; } }
  if (window.ResizeObserver) { var ro = new ResizeObserver(function (es) { es.forEach(function (e) { fit(e.target.querySelector('[data-frame]')); }); }); document.querySelectorAll('[data-media]').forEach(function (m) { ro.observe(m); }); }
  if (!('IntersectionObserver' in window)) { frames.forEach(load); return; }
  var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); load(e.target); } }); }, { rootMargin: '200px' });
  frames.forEach(function (f) { io.observe(f); });
})();
</script>
<?php page_end();
