<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$pdo  = db();
Payments::reconcileAndReload($pdo, (int) $user['id']);
$templates = $pdo->query('SELECT id, slug, name, description, thumbnail, kind, category, is_premium, price_usd, price_coins, membership_unlocks, image_spec, credit_alias FROM templates t WHERE ' . Creators::PUBLIC_WHERE . ' ORDER BY is_premium, price_usd, id')->fetchAll();
$owned = Access::purchasedTemplateIds((int) $user['id']);
$isLocked = static fn(array $t): bool => !Access::canUse($user, $t, $owned);

$data = array_fill_keys(array_keys(Template::FIELDS), '');
$errors = [];
$chosen = 0;
foreach ($templates as $t) {
    if (!$isLocked($t)) {
        $chosen = (int) $t['id'];   // la primera que el usuario puede usar
        break;
    }
}
if (is_string($_GET['template'] ?? null)) {   // viene del marketplace: ?template=<slug>
    foreach ($templates as $t) {
        if ($t['slug'] === $_GET['template'] && !$isLocked($t)) {
            $chosen = (int) $t['id'];
        }
    }
}
$userTier = Access::userTier($user);
$atLimit  = Access::atSiteLimit($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {   // PHP descartó el cuerpo: superó post_max_size
        flash('Las fotos superan el tamaño máximo permitido (40 MB en total). Elige fotos más ligeras.');
        redirect('create.php' . (is_string($_GET['template'] ?? null) ? '?template=' . rawurlencode($_GET['template']) : ''));
    }
    csrf_verify();
    [$data, $errors] = Template::sanitize($_POST);
    $chosen = (int) ($_POST['template_id'] ?? 0);

    $tpl = null;
    foreach ($templates as $t) {
        if ((int) $t['id'] === $chosen) {
            $tpl = $t;
        }
    }
    if (!$tpl) {
        $errors['template_id'] = 'Plantilla inválida.';
    } elseif ($isLocked($tpl)) {
        // Comprobación en servidor, independiente de lo que muestre la interfaz.
        $errors['template_id'] = Access::isPurchasable($tpl)
            ? 'Esta plantilla requiere Premium o comprarla por separado.'
            : 'Esta plantilla requiere Premium.';
    }

    $staged = ['staged' => [], 'errors' => [], 'dir' => null];
    if (!$errors && $tpl) {
        // Fotos: se validan y procesan (GD, WebP) ANTES de crear la página; los errores salen por campo.
        $staged = TemplateImages::stage(TemplateImages::parse($tpl['image_spec'] ?? null), $_FILES);
        $errors = $staged['errors'];
    }

    if (!$errors) {
        // Límite del plan, cobro de monedas y alta en una sola transacción (ver Sites::create).
        try {
            $res = Sites::create($user, $tpl, $data, TemplateImages::committer($staged['staged']));
        } finally {
            ImageStore::removeTempDir($staged['dir']);
        }
        if (isset($res['slug'])) {
            flash('¡Tu página está lista! Comparte el enlace.');
            redirect('dashboard.php');
        }
        $errors['template_id'] = (string) $res['error'];
    } else {
        ImageStore::removeTempDir($staged['dir']);
    }
}

// Con ?template=<slug> (viene de la Galería) se muestra SOLO esa plantilla: con muchas plantillas, repetir
// todo el catálogo aquí sería incómodo. Si la plantilla pedida no existe o está bloqueada, se muestra el selector
// completo como antes. «Cambiar plantilla» lleva de vuelta a la Galería (buscador, filtros y vista previa).
$single = false;
if (is_string($_GET['template'] ?? null)) {
    foreach ($templates as $t) {
        if ($t['slug'] === $_GET['template'] && !$isLocked($t)) {
            $single = true;
            $chosen = (int) $t['id'];   // en un POST con error, se mantiene la misma plantilla
        }
    }
}

$labels = ['your_name' => 'Tu nombre', 'partner_name' => 'Nombre de tu pareja', 'start_date' => 'Fecha en que empezaron', 'message' => 'Tu mensaje'];

$balance = Coins::balance((int) $user['id']);
$days    = Access::siteDays($user);
$usage   = Access::siteUsage($user);
$isMonth = $usage['mode'] === 'month';
$sel = null;
foreach ($templates as $t) {
    if ((int) $t['id'] === $chosen && !$isLocked($t)) {
        $sel = $t;
    }
}
$selCost = $sel ? Access::coinCost($user, $sel) : 0;
$short   = $sel && $balance < $selCost;
$img = static fn(string $n): string => e(url('assets/img/crear/' . $n . '.svg'));
$icon = static fn(string $n, int $s = 20, string $cls = ''): string => '<img src="' . e(url('assets/img/crear/' . $n . '.svg')) . '" alt="" width="' . $s . '" height="' . $s . '" class="shrink-0 ' . $cls . '">';
$helps = ['your_name' => 'Cómo quieres que aparezca tu nombre.', 'partner_name' => 'La persona a quien dedicas la página.', 'start_date' => 'Se usa para contar el tiempo juntos.', 'message' => 'Escríbelo desde el corazón; puedes usar varias líneas.'];
$icons = ['your_name' => 'ico-user', 'partner_name' => 'ico-users', 'start_date' => 'ico-calendar', 'message' => 'ico-message'];

$imgJson = static fn(array $t): string => (string) json_encode(TemplateImages::parse($t['image_spec'] ?? null), JSON_UNESCAPED_UNICODE);
$photoFields = $sel ? TemplateImages::parse($sel['image_spec'] ?? null) : [];

page_start('Crea tu página', 'max-w-5xl');
?>
<section class="mt-2 mb-6 flex items-center justify-between gap-6">
  <div>
    <h1 class="text-2xl md:text-3xl font-bold">Crea tu página</h1>
    <p class="mt-1 text-sm text-slate-600">Elige una plantilla, personalízala con tus datos y compártela en minutos.</p>
  </div>
  <img class="hidden md:block" src="<?= $img('hero-crear') ?>" alt="" width="200" height="125">
</section>

<ol class="mb-6 grid grid-cols-3 gap-2 text-center" id="pasos" aria-label="Progreso">
  <?php foreach ([['paso-1', 'step-plantilla', '1', 'Plantilla'], ['paso-2', 'step-datos', '2', 'Tus datos'], ['paso-3', 'step-listo', '3', 'Confirmar']] as [$aid, $sv, $num, $lab]): ?>
    <li>
      <a href="#<?= $aid ?>" data-step="<?= $aid ?>" class="group flex flex-col md:flex-row items-center justify-center gap-1 md:gap-3 min-h-[44px] rounded-2xl border border-rose-100 bg-white px-2 py-2 text-xs md:text-sm font-semibold text-slate-700 hover:border-rose-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 data-[done]:border-emerald-300 data-[done]:bg-emerald-50">
        <img src="<?= $img($sv) ?>" alt="" width="32" height="32">
        <span><span class="text-rose-700"><?= $num ?>.</span> <?= e($lab) ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ol>

<form method="post" enctype="multipart/form-data" id="crear" class="grid gap-8 lg:grid-cols-[1fr_380px] lg:items-start">
  <?= csrf_field() ?>
  <div class="space-y-8 min-w-0">
    <section id="paso-1" class="scroll-mt-4">
      <fieldset>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <legend class="font-semibold text-lg"><?= $single ? '1. Tu plantilla' : '1. Elige tu plantilla' ?></legend>
          <a class="text-sm text-rose-700 font-semibold underline min-h-[44px] inline-flex items-center" href="<?= e(url('index.php')) ?>"><?= $single ? 'Cambiar plantilla' : 'Ver la galería completa' ?></a>
        </div>
        <?php if (!Access::isFreePlan($user)): $tu = Access::templateUnlockUsage($user); if ((int) $tu['allowed'] > 0): ?>
          <p class="mb-3 text-xs text-slate-500">Plantillas de membresía este mes: <strong><?= $tu['used'] ?>/<?= $tu['allowed'] ?></strong> usadas<?= $tu['remaining'] === 0 ? ' · las que ya elegiste siguen gratis; las demás cuestan monedas hasta el ' . e(Access::monthResetLabel($tu['resets_at'])) : '' ?>.</p>
        <?php endif; endif; ?>
        <?php if ($single):
            // Ya eligió plantilla (viene de la Galería): sin selector, solo un resumen compacto de la elegida.
            $one = null;
            foreach ($templates as $tt) {
                if ((int) $tt['id'] === $chosen) {
                    $one = $tt;
                }
            }
            $oneCost = Access::coinCost($user, $one);
            $oneQuota = (int) $one['membership_unlocks'] === 1 && (int) $one['price_coins'] > 0; ?>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-rose-300 bg-white p-3 ring-2 ring-rose-200">
            <input class="sr-only" type="radio" name="template_id" value="<?= (int) $one['id'] ?>" data-name="<?= e($one['name']) ?>" data-cost="<?= (int) $oneCost ?>" data-slug="<?= e($one['slug']) ?>" data-images="<?= e($imgJson($one)) ?>" checked>
            <img src="<?= e(url($one['thumbnail'] ? 'assets/thumbs/' . $one['thumbnail'] : 'assets/img/paginas/thumb-fallback.svg')) ?>" alt="" width="96" height="58" class="h-14 w-24 shrink-0 rounded-lg object-cover">
            <div class="min-w-[9rem] flex-1">
              <p class="font-semibold leading-snug"><?= e($one['name']) ?></p><?php if ($one['kind'] === 'utpl' && $one['credit_alias']): ?><p class="text-xs text-slate-500">Por <?= e((string) $one['credit_alias']) ?></p><?php endif; ?>
              <p class="mt-0.5 text-xs font-semibold text-emerald-800"><?= $oneCost > 0 ? $oneCost . ' monedas' : ($oneQuota ? 'Gratis con tu cupo · valor ' . (int) $one['price_coins'] . ' monedas' : 'Gratis') ?></p>
            </div>
            <a class="w-full sm:w-auto shrink-0 inline-flex items-center gap-1 min-h-[44px] text-xs text-rose-700 font-semibold underline" href="<?= e(url('preview.php?t=' . rawurlencode((string) $one['slug']))) ?>" target="_blank" rel="noopener"><?= $icon('ico-eye', 16) ?>Vista previa<span class="sr-only"> de <?= e($one['name']) ?> (pestaña nueva)</span></a>
          </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
        <?php foreach ($templates as $t): $locked = $isLocked($t); $cost = Access::coinCost($user, $t); ?>
          <div class="relative flex flex-col rounded-2xl border border-rose-100 bg-white overflow-hidden text-sm transition has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50 has-[:checked]:ring-2 has-[:checked]:ring-rose-300 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-rose-600 <?= $locked ? '' : 'hover:border-rose-300' ?>">
            <label class="block <?= $locked ? 'opacity-60' : 'cursor-pointer' ?>">
              <input class="sr-only" type="radio" name="template_id" value="<?= (int) $t['id'] ?>" data-name="<?= e($t['name']) ?>" data-cost="<?= (int) $cost ?>" data-slug="<?= e($t['slug']) ?>" data-images="<?= e($imgJson($t)) ?>" <?= (int) $t['id'] === $chosen && !$locked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
              <span class="relative block">
                <img src="<?= e(url($t['thumbnail'] ? 'assets/thumbs/' . $t['thumbnail'] : 'assets/img/paginas/thumb-fallback.svg')) ?>" alt="" width="400" height="240" loading="lazy" class="w-full aspect-[5/3] object-cover">
                <?php if ($t['kind'] === 'php'): ?><span class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold bg-indigo-100 text-indigo-800"><img src="<?= e(url('assets/img/paginas/badge-interactiva.svg')) ?>" alt="" width="14" height="14">Interactiva</span><?php endif; ?>
              </span>
              <span class="block p-3 pb-1">
                <span class="flex items-center gap-1.5 font-semibold"><?php if ($locked): ?><img src="<?= e(url('assets/img/paginas/ico-lock.svg')) ?>" alt="" width="16" height="16" loading="lazy"><?php endif; ?><?= e($t['name']) ?></span><?php if ($t['kind'] === 'utpl' && $t['credit_alias']): ?><span class="block text-xs text-slate-500">Por <?= e((string) $t['credit_alias']) ?></span><?php endif; ?>
                <span class="mt-1 block text-xs font-semibold <?= $locked ? 'text-amber-800' : 'text-emerald-800' ?>"><?= $locked ? (Access::isPurchasable($t) ? 'Bloqueada · $' . e(wompi_format_usd(Access::priceInCents($t))) . ' USD' : 'Bloqueada · Solo Premium') : ($cost > 0 ? $cost . ' monedas' : ((int) $t['membership_unlocks'] === 1 && (int) $t['price_coins'] > 0 ? 'Gratis con tu cupo · valor ' . (int) $t['price_coins'] . ' monedas' : 'Gratis')) ?></span>
              </span>
            </label>
            <a class="mx-3 mb-1 inline-flex items-center gap-1 min-h-[44px] text-xs text-rose-700 font-semibold underline" href="<?= e(url('preview.php?t=' . rawurlencode((string) $t['slug']))) ?>" target="_blank" rel="noopener"><?= $icon('ico-eye', 16) ?>Vista previa<span class="sr-only"> de <?= e($t['name']) ?> (pestaña nueva)</span></a>
          </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </fieldset>
      <?php if (isset($errors['template_id'])): ?><p role="alert" class="mt-2 text-sm text-rose-700"><?= e($errors['template_id']) ?></p><?php endif; ?>
    </section>

    <section id="paso-2" class="scroll-mt-4 space-y-4">
      <h2 class="font-semibold text-lg">2. Tus datos</h2>
      <?php foreach ($labels as $f => $label): $err = $errors[$f] ?? null; $max = Template::FIELDS[$f]; ?>
        <div>
          <label class="block text-sm font-semibold mb-1" for="<?= $f ?>"><?= e($label) ?></label>
          <div class="relative">
            <span class="pointer-events-none absolute left-3 <?= $f === 'message' ? 'top-3.5' : 'top-1/2 -translate-y-1/2' ?>"><?= $icon($icons[$f], 20) ?></span>
            <?php $aria = 'aria-describedby="' . $f . '-help' . ($err ? ' ' . $f . '-err' : '') . '"' . ($err ? ' aria-invalid="true"' : ''); ?>
            <?php if ($f === 'message'): ?>
              <textarea id="<?= $f ?>" name="<?= $f ?>" rows="5" maxlength="<?= $max ?>" required <?= $aria ?> class="<?= INPUT_CLS ?> pl-11 <?= $err ? 'border-rose-500' : '' ?>"><?= e($data[$f]) ?></textarea>
            <?php else: ?>
              <input id="<?= $f ?>" name="<?= $f ?>" type="<?= $f === 'start_date' ? 'date' : 'text' ?>" value="<?= e($data[$f]) ?>"
                     maxlength="<?= $max ?>" <?= $f === 'start_date' ? 'max="' . date('Y-m-d') . '"' : '' ?> required <?= $aria ?> class="<?= INPUT_CLS ?> pl-11 <?= $err ? 'border-rose-500' : '' ?>">
            <?php endif; ?>
          </div>
          <div class="mt-1 flex justify-between gap-3 text-xs text-slate-600">
            <span id="<?= $f ?>-help"><?= e($helps[$f]) ?></span>
            <?php if ($f === 'message'): ?><span data-counter="message" data-max="<?= $max ?>" aria-hidden="true"></span><?php endif; ?>
          </div>
          <?php if ($err): ?><p id="<?= $f ?>-err" role="alert" class="text-sm text-rose-700 mt-1"><?= e($err) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div id="fotos" data-photos class="space-y-4 pt-2" <?= $photoFields ? '' : 'hidden' ?>>
        <h3 class="font-semibold">Tus fotos</h3>
        <?php foreach ($photoFields as $pf): $k = 'img_' . $pf['key']; $perr = $errors[$k] ?? null; ?>
          <div data-photo>
            <label class="block text-sm font-semibold mb-1" for="<?= e($k) ?>"><?= e($pf['label']) ?><?php if ($pf['required']): ?> <span class="text-rose-700" aria-hidden="true">*</span><span class="sr-only">(obligatoria)</span><?php else: ?> <span class="font-normal text-slate-600">(opcional)</span><?php endif; ?></label>
            <div class="flex items-center gap-3">
              <img data-photo-thumb hidden alt="" width="64" height="64" class="h-16 w-16 shrink-0 rounded-lg object-cover border border-rose-100">
              <input id="<?= e($k) ?>" name="<?= e($k) ?>" type="file" accept="image/jpeg,image/png,image/webp" <?= $pf['required'] ? 'required' : '' ?> aria-describedby="<?= e($k) ?>-help<?= $perr ? ' ' . e($k) . '-err' : '' ?>"<?= $perr ? ' aria-invalid="true"' : '' ?> class="min-h-[44px] w-full min-w-0 text-sm text-slate-700 file:mr-3 file:min-h-[44px] file:rounded-xl file:border-0 file:bg-rose-100 file:px-4 file:font-semibold file:text-rose-800">
            </div>
            <p id="<?= e($k) ?>-help" class="mt-1 text-xs text-slate-600">JPG, PNG o WebP, máx. 5 MB</p>
            <p id="<?= e($k) ?>-err" data-photo-err role="alert" class="text-sm text-rose-700 mt-1"><?= $perr ? e($perr) : '' ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <aside class="space-y-4 lg:sticky lg:top-4">
    <details id="preview-box" class="rounded-2xl border border-rose-100 bg-white p-4" data-embed="<?= e(url('preview.php')) ?>" hidden>
      <summary class="font-semibold cursor-pointer min-h-[44px] flex items-center gap-2 lg:cursor-default lg:pointer-events-none"><?= $icon('ico-eye', 20) ?><span class="lg:hidden">Ver vista previa</span><span class="hidden lg:inline">Vista previa en vivo</span></summary>
      <div class="relative mt-3 mx-auto w-full max-w-[260px] lg:max-w-none aspect-[9/16] max-h-[520px] rounded-xl overflow-hidden border border-rose-100 bg-rose-50">
        <img data-preview-empty src="<?= $img('preview-empty') ?>" alt="" width="240" height="160" class="absolute inset-0 m-auto w-3/4">
        <iframe data-preview-frame sandbox="allow-scripts" title="Vista previa de tu página" loading="lazy" class="absolute inset-0 w-full h-full bg-white opacity-0 transition-opacity"></iframe>
      </div>
    </details>

    <section id="paso-3" class="scroll-mt-4 rounded-2xl border border-rose-100 bg-white p-4 space-y-3" data-balance="<?= $balance ?>">
      <h2 class="font-semibold text-lg">3. Confirmar</h2>
      <dl class="text-sm space-y-2" aria-live="polite">
        <div class="flex justify-between gap-3"><dt class="text-slate-600">Plantilla</dt><dd class="font-semibold text-right" data-sum="name"><?= $sel ? e($sel['name']) : 'Sin elegir' ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-600">Costo</dt><dd class="font-semibold" data-sum="cost"><?= $selCost > 0 ? $selCost . ' monedas' : 'Gratis' ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-600">Tu saldo</dt><dd class="font-semibold"><?= $balance ?> monedas</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-600">Saldo después</dt><dd class="font-semibold" data-sum="after"><?= max(0, $balance - $selCost) ?> monedas</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-600">Duración</dt><dd class="font-semibold inline-flex items-center gap-1"><?= $icon('ico-clock', 16) ?>Dura <?= $days ?> días</dd></div>
        <?php if ($isMonth): ?><div class="flex justify-between gap-3"><dt class="text-slate-600">Páginas gratuitas este mes</dt><dd class="font-semibold"><?= (int) $usage['remaining'] ?> restantes</dd></div><?php endif; ?>
      </dl>
      <p data-sum="short" role="status" class="text-sm text-amber-800 bg-amber-50 rounded-xl px-3 py-2 <?= $short ? '' : 'hidden' ?>">Te faltan monedas para esta plantilla. <a class="font-semibold underline" href="<?= e(url('tienda.php#monedas')) ?>">Recargar monedas</a></p>
      <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-rose-600">Crear página</button>
      <p class="text-xs text-slate-600 text-center"><a class="underline" href="<?= e(url('tienda.php#monedas')) ?>">Recargar monedas</a></p>
    </section>
    <?php if (isset($tpl) && is_array($tpl) && $balance < Access::coinCost($user, $tpl)): ?>
      <?= empty_state('coins', 'Te faltan monedas', 'Esta plantilla cuesta ' . Access::coinCost($user, $tpl) . ' monedas y tienes ' . $balance . '.', url('tienda.php#monedas'), 'Recargar monedas') ?>
    <?php endif; ?>
  </aside>
</form>

<?php $buyable = array_filter($templates, static fn(array $t): bool => ($isLocked($t) || $atLimit) && Access::isPurchasable($t)); ?>
<?php if ($atLimit): ?>
  <p class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 text-amber-900 text-sm px-4 py-3"><?= $isMonth ? 'Usaste tus ' . (int) $usage['allowed'] . ' páginas gratuitas de este mes. Se reinician el ' . e(Access::monthResetLabel($usage['resets_at'])) . '.' : 'Usaste las ' . Access::siteAllowance($user) . ' páginas de tu plan.' ?> Compra una plantilla extra<?= $userTier && (int) $userTier['template_discount_pct'] > 0 ? ' con ' . (int) $userTier['template_discount_pct'] . ' % de descuento' : '' ?> o sube de plan.</p>
<?php endif; ?>
<?php if ((!$single && array_filter($templates, $isLocked)) || $atLimit): ?>
  <section class="mt-8 rounded-2xl bg-white border border-rose-100 p-4 space-y-3">
    <h2 class="font-semibold"><?= $atLimit ? 'Páginas extra' : 'Desbloquear plantillas' ?></h2>
    <?php foreach ($buyable as $t): ?>
      <form method="post" action="<?= e(url('checkout_wompi.php')) ?>" class="flex items-center justify-between gap-3 text-sm">
        <?= csrf_field() ?><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
        <span><?= e($t['name']) ?></span>
        <button class="rounded-xl border border-rose-300 text-rose-700 font-semibold px-4 py-2 hover:bg-rose-50 transition">Comprar $<?= e(wompi_format_usd(Access::extraTemplatePriceInCents($t, $userTier))) ?></button>
      </form>
    <?php endforeach; ?>
    <a class="block text-center text-sm text-rose-600 font-semibold" href="<?= e(url('tienda.php#membresias')) ?>">Ver membresías (todas las plantillas y más páginas)</a>
  </section>
<?php endif; ?>
<script src="<?= e(url('assets/js/create.js')) ?>" defer nonce="<?= e(csp_nonce()) ?>"></script>
<?php page_end();
