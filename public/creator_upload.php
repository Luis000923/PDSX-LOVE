<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Publicar en la Galería: envía una plantilla pública (HTML con marcadores {{campo}}) para revisión.
 * NO usa el cupo de HTML propio (html_uploads). Queda pendiente e inactiva hasta que un admin la apruebe.
 * Ver docs/CREADORES.md.
 */
$user = require_login();
$uid  = (int) $user['id'];
$cfg  = Creators::config();
$errors = [];
$in = ['name' => '', 'description' => '', 'category' => 'romantico', 'photos' => '0', 'price' => (string) max((int) $cfg['min_price'], min((int) $cfg['max_price'], 10)), 'quota' => '', 'alias' => ''];
$myAlias = Creators::alias($uid);
$counts = Creators::counts($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Los archivos superan el tamaño máximo permitido.');
        redirect('creator_upload.php');
    }
    csrf_verify();
    foreach (['name', 'description', 'category', 'photos', 'price', 'alias'] as $k) {
        $in[$k] = is_string($_POST[$k] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $_POST[$k])) : '';
    }
    $in['quota'] = !empty($_POST['quota']) ? '1' : '';
    $photos = preg_match('/^\d{1,2}$/', $in['photos']) === 1 ? (int) $in['photos'] : -1;
    $price = preg_match('/^\d{1,5}$/', $in['price']) === 1 ? (int) $in['price'] : -1;

    if (empty($_POST['consent'])) {
        $errors[] = 'Debes confirmar la casilla de conformidad para publicar.';
    }
    if ($counts['pending'] >= $cfg['max_pending']) {
        $errors[] = 'Ya tienes ' . $cfg['max_pending'] . ' envíos pendientes de revisión. Espera a que se revisen.';
    } elseif ($counts['total'] >= $cfg['max_templates']) {
        $errors[] = 'Alcanzaste el máximo de ' . $cfg['max_templates'] . ' plantillas públicas.';
    }
    $newAlias = null;
    if ($myAlias === null) {
        $v = Ranking::validateAlias($in['alias']);
        if ($v['error'] !== null) {
            $errors[] = $v['error'];
        } else {
            $newAlias = $v['value'];
        }
    }
    $file = $_FILES['file'] ?? null;
    $thumb = $_FILES['thumb'] ?? null;
    $html = '';
    if (!$errors) {
        $okUp = static fn ($f): bool => is_array($f) && is_string($f['tmp_name'] ?? null) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']);
        if (!$okUp($file)) {
            $errors[] = 'Elige el archivo .html de tu plantilla (hasta 512 KB).';
        } else {
            $r = Creators::inspectUpload($file['tmp_name'], (string) ($file['name'] ?? ''));
            $errors = $r['errors'];
            $html = $r['html'];
        }
        if (!$okUp($thumb)) {
            $errors[] = 'La miniatura es obligatoria (JPG, PNG o WebP, hasta 5 MB).';
        }
    }
    $tmp = null;
    try {
        if (!$errors) {
            $tmp = ImageStore::makeTempDir();
            $img = $tmp === null ? 'El servidor no puede procesar imágenes ahora mismo.' : ImageStore::thumbnail((string) $thumb['tmp_name'], $tmp, 800, 480);
            if (is_string($img)) {
                $errors[] = $img;
            } else {
                $res = Creators::submit($uid, ['name' => $in['name'], 'description' => $in['description'], 'category' => $in['category'],
                    'photos' => $photos, 'price' => $price, 'quota' => $in['quota'] === '1'], $html, $img['path'], $newAlias);
                if ($res['errors'] === []) {
                    flash('¡Recibimos tu plantilla! La revisaremos y te avisaremos aquí mismo cuando esté publicada.');
                    redirect('creator.php');
                }
                $errors = $res['errors'];
            }
        }
    } finally {
        ImageStore::removeTempDir($tmp);
    }
}

$card = 'rounded-2xl bg-white border border-rose-100 p-5';
$lbl = 'block text-sm font-semibold text-slate-700 mb-1';
$field = INPUT_CLS . ' min-h-[44px]';
page_start('Publicar en la Galería', 'max-w-3xl');
?>
<section class="mt-2 mb-4">
  <h1 class="text-2xl font-bold">Publicar en la Galería</h1>
  <p class="mt-1 text-sm text-slate-600">Comparte una plantilla con todos y gana <strong><?= (int) $cfg['share_pct'] ?> %</strong> del precio en monedas cada vez que otra persona la use. Un admin la revisa antes de publicarla.
    Tu página privada no cambia: <a class="font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('upload_html.php')) ?>">Subir tu propio HTML</a>. <a class="font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('creator.php')) ?>">Mis plantillas públicas</a>.</p>
</section>

<form method="post" enctype="multipart/form-data" class="<?= $card ?> space-y-4" aria-describedby="<?= $errors ? 'form-errors' : 'form-help' ?>">
  <?= csrf_field() ?>
  <?php if ($errors): ?>
    <div id="form-errors" role="alert" class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm px-4 py-3">
      <p class="font-semibold">No pudimos enviar tu plantilla:</p>
      <ul class="mt-1 list-disc pl-5 space-y-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <p id="form-help" class="text-xs text-slate-500">Pendientes: <?= (int) $counts['pending'] ?>/<?= (int) $cfg['max_pending'] ?> · Plantillas públicas: <?= (int) $counts['total'] ?>/<?= (int) $cfg['max_templates'] ?>. Este envío no gasta tu cupo mensual de HTML propio.</p>
  <div>
    <label class="<?= $lbl ?>" for="name">Nombre (máx. 60)</label>
    <input id="name" name="name" required maxlength="60" class="<?= $field ?>" value="<?= e($in['name']) ?>" autocomplete="off">
  </div>
  <div>
    <label class="<?= $lbl ?>" for="description">Descripción (máx. 200)</label>
    <textarea id="description" name="description" maxlength="200" rows="2" class="<?= INPUT_CLS ?>"><?= e($in['description']) ?></textarea>
  </div>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label class="<?= $lbl ?>" for="category">Categoría</label>
      <select id="category" name="category" class="<?= $field ?>"><?php foreach (Template::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $in['category'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </div>
    <div>
      <label class="<?= $lbl ?>" for="photos">Fotos que pide (0–<?= Creators::MAX_PHOTOS ?>)</label>
      <input id="photos" name="photos" type="number" min="0" max="<?= Creators::MAX_PHOTOS ?>" required class="<?= $field ?>" value="<?= e($in['photos']) ?>">
    </div>
  </div>
  <div>
    <label class="<?= $lbl ?>" for="price">Precio propuesto en monedas (<?= (int) $cfg['min_price'] ?>–<?= (int) $cfg['max_price'] ?>)</label>
    <input id="price" name="price" type="number" inputmode="numeric" min="<?= (int) $cfg['min_price'] ?>" max="<?= (int) $cfg['max_price'] ?>" required class="<?= $field ?>" value="<?= e($in['price']) ?>">
    <p class="mt-1 text-xs text-slate-500">El admin puede ajustarlo al aprobar.</p>
  </div>
  <label class="flex items-start gap-3 min-h-[44px] text-sm"><input type="checkbox" name="quota" value="1" class="mt-1 h-5 w-5 accent-rose-600"<?= $in['quota'] === '1' ? ' checked' : '' ?>>
    <span>Incluir en el cupo mensual de membresías <span class="text-slate-500">(sin marcar, solo se usa con monedas)</span></span></label>
  <div>
    <label class="<?= $lbl ?>" for="file">Archivo HTML (.html, hasta 512 KB)</label>
    <input id="file" name="file" type="file" required accept=".html,.htm,text/html" class="block w-full text-sm min-h-[44px] rounded-xl border border-rose-200 bg-white p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:font-semibold file:text-rose-700" aria-describedby="file-help">
    <p id="file-help" class="mt-1 text-xs text-slate-500">Un solo archivo con marcadores <code>{{your_name}}</code> <code>{{partner_name}}</code> <code>{{start_date}}</code> <code>{{days_together}}</code> <code>{{message}}</code> y, si pides fotos, <code>{{img_foto1}}</code>… Debe usar al menos uno. Si no usa marcadores, publícala como HTML propio.</p>
  </div>
  <div>
    <label class="<?= $lbl ?>" for="thumb">Miniatura (JPG, PNG o WebP; se recorta a 800×480)</label>
    <input id="thumb" name="thumb" type="file" required accept="image/jpeg,image/png,image/webp" class="block w-full text-sm min-h-[44px] rounded-xl border border-rose-200 bg-white p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:font-semibold file:text-rose-700">
  </div>
  <?php if ($myAlias === null): ?>
    <div>
      <label class="<?= $lbl ?>" for="alias">Tu alias público (se muestra como autor)</label>
      <input id="alias" name="alias" required minlength="<?= Ranking::ALIAS_MIN ?>" maxlength="<?= Ranking::ALIAS_MAX ?>" class="<?= $field ?>" value="<?= e($in['alias']) ?>" autocomplete="nickname">
      <p class="mt-1 text-xs text-slate-500">Elegirlo ahora es gratis y no te muestra en el Top de donadores.</p>
    </div>
  <?php else: ?>
    <p class="text-sm text-slate-600">Autor mostrado: <strong><?= e($myAlias) ?></strong> (tu alias público; <a class="underline text-rose-700" href="<?= e(url('profile.php#alias')) ?>">cambiarlo</a> puede costar monedas).</p>
  <?php endif; ?>
  <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="consent" value="1" required class="mt-1 h-5 w-5 accent-rose-600">
    <span>Soy autor o tengo derechos sobre esta plantilla, no contiene datos personales ni contenido de terceros y acepto que mi alias se muestre como autor y las <a class="underline text-rose-700" href="<?= e(url('terms.php#creadores')) ?>" target="_blank" rel="noopener">condiciones del programa de creadores</a>.</span></label>
  <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2">Enviar a revisión</button>
</form>
<?php page_end();
