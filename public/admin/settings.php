<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Ajustes globales: anuncios, aviso global y precio Premium. */
Admin::guard();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();

    $adsHtml      = trim((string) ($_POST['ads_html'] ?? ''));
    $announcement = trim((string) ($_POST['announcement_text'] ?? ''));
    $price        = trim((string) ($_POST['premium_price_usd'] ?? ''));

    if ($adsHtml !== '') {
        $errors = array_merge($errors, Admin::validateAdHtml($adsHtml));
    }
    if (mb_strlen($announcement) > 200) {
        $errors[] = 'El aviso global no puede superar 200 caracteres.';
    }
    $priceCents = $price === '' ? null : wompi_usd_to_cents($price);
    if ($price !== '' && $priceCents === null) {
        $errors[] = 'El precio Premium debe ser un monto en USD entre 0.01 y 99999.99, con hasta 2 decimales.';
    }
    if (!empty($_POST['announcement_enabled']) && $announcement === '') {
        $errors[] = 'No puedes activar el aviso global sin texto.';
    }

    if (!$errors) {
        Admin::setSetting('ads_enabled', !empty($_POST['ads_enabled']) ? '1' : '0');
        Admin::setSetting('ads_html', $adsHtml);
        Admin::setSetting('announcement_enabled', !empty($_POST['announcement_enabled']) ? '1' : '0');
        Admin::setSetting('announcement_text', $announcement);
        Admin::setSetting('premium_price_usd', $priceCents === null ? '' : wompi_format_usd($priceCents));
        Admin::log('settings.update', 'anuncios y precio');
        flash('Ajustes guardados.');
        redirect('admin/settings.php');
    }
}

$settings = Admin::settings();
$envPrice = wompi_format_usd(wompi_usd_to_cents((string) env('PREMIUM_PRICE_USD', '4.99')) ?? WOMPI_DEFAULT_PRICE_IN_CENTS);

// En un envío con errores se conserva lo que el admin escribió.
$val = static fn(string $k): string => $_SERVER['REQUEST_METHOD'] === 'POST' && $errors ? trim((string) ($_POST[$k] ?? '')) : $settings[$k];
$adsOn  = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors ? !empty($_POST['ads_enabled']) : $settings['ads_enabled'] === '1';
$annOn  = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors ? !empty($_POST['announcement_enabled']) : $settings['announcement_enabled'] === '1';
$annTxt = $val('announcement_text');

$H2 = 'font-semibold text-slate-900';
$LBL = 'block text-sm font-semibold text-slate-800 mb-1';
$HELP = 'text-xs text-slate-500 mt-1';

admin_page_start('Ajustes', 'settings', 'Publicidad, avisos y precio. Los cambios se aplican al guardar.');
admin_errors($errors);
?>
<form method="post" class="space-y-6 max-w-3xl">
  <?= csrf_field() ?>

  <section class="<?= ADMIN_CARD_CLS ?>" aria-labelledby="h-ads">
    <h2 id="h-ads" class="<?= $H2 ?>">Publicidad</h2>
    <p class="<?= $HELP ?> mb-4">Se muestra solo en las páginas públicas de cuentas gratuitas.</p>
    <label class="flex items-center gap-2 text-sm font-semibold">
      <input type="checkbox" name="ads_enabled" value="1" class="h-4 w-4 accent-rose-600" <?= $adsOn ? 'checked' : '' ?>>
      Mostrar publicidad
    </label>
    <label class="<?= $LBL ?> mt-4" for="ads_html">HTML del banner</label>
    <textarea id="ads_html" name="ads_html" rows="4" maxlength="4096" class="<?= ADMIN_INPUT_CLS ?> font-mono text-xs"
              placeholder="&lt;a href=&quot;https://…&quot;&gt;&lt;img src=&quot;/love/assets/thumbs/banner.png&quot; alt=&quot;&quot;&gt;&lt;/a&gt;"><?= e($val('ads_html')) ?></textarea>
    <p class="<?= $HELP ?>">Sin <code>&lt;script&gt;</code>, <code>&lt;iframe&gt;</code> ni <code>onclick=</code>; las imágenes deben servirse desde este dominio (CSP). Si lo dejas vacío se muestra el marcador «Publicidad».</p>
  </section>

  <section class="<?= ADMIN_CARD_CLS ?>" aria-labelledby="h-ann">
    <h2 id="h-ann" class="<?= $H2 ?>">Aviso global</h2>
    <p class="<?= $HELP ?> mb-4">Una franja con un mensaje en la cabecera de la app (promociones, mantenimiento).</p>
    <label class="flex items-center gap-2 text-sm font-semibold">
      <input type="checkbox" name="announcement_enabled" value="1" class="h-4 w-4 accent-rose-600" <?= $annOn ? 'checked' : '' ?>>
      Mostrar aviso en la app
    </label>
    <label class="<?= $LBL ?> mt-4" for="announcement_text">Texto del aviso</label>
    <input id="announcement_text" name="announcement_text" maxlength="200" class="<?= ADMIN_INPUT_CLS ?>"
           placeholder="Ej.: 30 % de descuento con el código AMOR30 hasta el domingo" value="<?= e($annTxt) ?>">
    <p class="<?= $HELP ?>">Máximo 200 caracteres. Texto plano: se escapa antes de mostrarse.</p>
    <p class="text-xs font-semibold text-slate-500 mt-4 mb-1">Vista previa <span class="font-normal">(estado guardado<?= $annOn ? '' : ', el aviso está apagado' ?>)</span></p>
    <?php if ($annTxt !== ''): ?>
      <div class="rounded-lg bg-slate-900 text-white text-sm text-center px-4 py-2 <?= $annOn ? '' : 'opacity-50' ?>"><?= e($annTxt) ?></div>
    <?php else: ?>
      <p class="rounded-lg border border-dashed border-slate-300 text-xs text-slate-500 px-4 py-3">Escribe un texto para ver cómo se verá.</p>
    <?php endif; ?>
  </section>

  <section class="<?= ADMIN_CARD_CLS ?>" aria-labelledby="h-price">
    <h2 id="h-price" class="<?= $H2 ?>">Precio Premium</h2>
    <p class="<?= $HELP ?> mb-4">Wompi El Salvador cobra en dólares (USD).</p>
    <div class="max-w-xs">
      <label class="<?= $LBL ?>" for="premium_price_usd">Precio (USD)</label>
      <input id="premium_price_usd" name="premium_price_usd" type="number" inputmode="decimal" min="0.01" max="99999.99" step="0.01"
             class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($val('premium_price_usd')) ?>" placeholder="<?= e($envPrice) ?>">
      <p class="<?= $HELP ?>">Vacío = usar el valor por defecto del servidor ($<?= e($envPrice) ?>).</p>
    </div>
  </section>

  <div><button class="<?= ADMIN_BTN_CLS ?>">Guardar ajustes</button></div>
</form>
<?php admin_page_end();
