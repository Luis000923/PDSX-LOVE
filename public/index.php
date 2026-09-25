<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Galería de plantillas: búsqueda, filtro por categoría y orden (todo con valores de lista blanca + PDO). */
$cat  = is_string($_GET['cat'] ?? null) && array_key_exists($_GET['cat'], Template::CATEGORIES) ? $_GET['cat'] : '';
$sortKey = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], ['popular', 'new', 'price'], true) ? $_GET['sort'] : 'popular';
$order = ['popular' => 'uses DESC, t.id DESC', 'new' => 't.id DESC', 'price' => 't.price_usd ASC, t.is_premium ASC, t.id DESC'][$sortKey];
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 50) : '';

$sql = 'SELECT t.id, t.slug, t.name, t.description, t.thumbnail, t.kind, t.category, t.is_premium, t.price_usd,
               (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id) AS uses
          FROM templates t WHERE t.is_active = 1' . ($cat !== '' ? ' AND t.category = ?' : '')
          . ($q !== '' ? " AND (t.name LIKE ? ESCAPE '!' OR t.description LIKE ? ESCAPE '!')" : '') . " ORDER BY $order";
$params = $cat !== '' ? [$cat] : [];
if ($q !== '') {
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($params, $like, $like);
}
$st = db()->prepare($sql);
$st->execute($params);
$templates = $st->fetchAll();

$user  = current_user();
$owned = $user ? Access::purchasedTemplateIds((int) $user['id']) : [];
$link  = static function (string $c, string $s, string $qq): string {
    $qs = http_build_query(array_filter(['q' => $qq, 'cat' => $c, 'sort' => $s !== 'popular' ? $s : '']));
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
    <a href="#plantillas" class="mt-5 inline-flex items-center justify-center min-h-[44px] px-8 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2">Ver plantillas</a>
  </div>
  <img src="<?= e(url('assets/img/paginas/hero-galeria.svg')) ?>" alt="" width="320" height="200" loading="lazy" class="hidden md:block justify-self-end w-full max-w-xs h-auto">
</section>

<section id="plantillas" class="mt-8 scroll-mt-4 space-y-3" aria-label="Buscar y filtrar">
  <form method="get" action="<?= e(url('index.php')) ?>" class="flex gap-2" role="search">
    <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
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

  <div class="flex items-center justify-between gap-3 text-sm">
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
      $free  = !Access::isPaid($t);
      $mine  = $user && Access::canUse($user, $t, $owned);
      $locked = !$free && !$mine;
      $label = $free ? 'Gratis' : ($mine ? 'Desbloqueada' : ($cents > 0 ? '$' . wompi_format_usd($cents) : 'Membresía'));
      $badge = $free || $mine ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
      $prev  = e(url('preview.php?t=' . rawurlencode((string) $t['slug']))); ?>
    <article class="group rounded-2xl bg-white border border-rose-100 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5 transition flex flex-col">
      <a href="<?= $prev ?>" target="_blank" rel="noopener" class="relative block focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rose-600" aria-label="Vista previa de <?= e($t['name']) ?>">
        <?php if ($t['thumbnail']): ?>
          <img src="<?= e(url('assets/thumbs/' . $t['thumbnail'])) ?>" alt="" width="400" height="240" loading="lazy" class="w-full aspect-[5/3] object-cover">
        <?php else: ?>
          <img src="<?= e(url('assets/img/paginas/thumb-fallback.svg')) ?>" alt="" width="400" height="240" loading="lazy" class="w-full aspect-[5/3] object-cover">
        <?php endif; ?>
        <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold <?= $badge ?>"><?= $locked ? $ico('ico-lock', 12) : '' ?><?= e($label) ?></span>
        <?php if ($t['kind'] === 'php'): ?><span class="absolute top-3 right-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold bg-indigo-100 text-indigo-800"><?= $ico('badge-interactiva', 16) ?>Interactiva</span><?php endif; ?>
      </a>
      <div class="p-4 flex flex-col flex-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-rose-600"><?= e(Template::CATEGORIES[$t['category']] ?? '') ?></p>
        <h2 class="mt-0.5 font-semibold text-lg leading-snug"><?= e($t['name']) ?></h2>
        <?php if ($t['description']): ?><p class="mt-1 text-sm text-slate-600 line-clamp-2"><?= e($t['description']) ?></p><?php endif; ?>
        <p class="mt-2 text-xs text-slate-500">
          <?= $locked ? ($cents > 0 ? 'Compra suelta o con membresía · ' : 'Con membresía · ') : '' ?><?= (int) $t['uses'] ?> página<?= (int) $t['uses'] === 1 ? '' : 's' ?> creada<?= (int) $t['uses'] === 1 ? '' : 's' ?>
        </p>
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
<?php page_end();
