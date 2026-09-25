<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/HtmlScanner.php';
require_once ROOT . '/src/UserHtml.php';

/**
 * «Subir mi plantilla»: UN solo flujo para HTML propio PRIVADO (cupo mensual, publicación directa) y PÚBLICO (revisión y
 * Galería, sin consumir ese cupo). Modo por ?modo=publica; ?desde={id de página} precarga el HTML de una página privada.
 * Privada: el documento se sirve aislado (frame.php); las fotos reales se suben aquí ({{img_foto_N}}).
 * Pública: el nº de fotos solo define image_spec; los compradores suben las suyas en create.php. Ver docs/HTML_PROPIO.md y docs/CREADORES.md.
 */
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();
$cfg  = Creators::config();
$errors = [];
$ferr = [];   // errores por campo (fotos)
$in = ['mode' => (($_GET['modo'] ?? '') === 'publica') ? 'publica' : 'privada', 'page_name' => '', 'tpl_name' => '', 'description' => '', 'category' => 'romantico',
       'photos' => '0', 'price' => (string) max((int) $cfg['min_price'], min((int) $cfg['max_price'], 10)), 'quota' => '', 'alias' => ''];
$desde = (int) ($_POST['desde'] ?? $_GET['desde'] ?? 0);
$fromSite = null;
if ($desde > 0) {
    $fromSite = Creators::htmlFromSite($uid, $desde);
    $in['mode'] = 'publica';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $in['tpl_name'] = mb_substr($fromSite['name'], 0, 60);
        $errors = $fromSite['errors'];
    }
}

$tplSt = $pdo->prepare("SELECT id, slug, name, kind, file, price_usd, price_coins, is_premium, membership_unlocks FROM templates WHERE slug = 'html-propio' AND kind = 'user'");
$tplSt->execute();
$tpl = $tplSt->fetch() ?: null;

$usage = Access::htmlUploadUsage($user);
$resetTxt = Access::monthResetLabel($usage['resets_at']);
$quotaMsg = static fn (array $u, string $when): string
    => ($u['allowed'] === 1 ? 'Usaste tu subida de HTML propio de este mes.' : 'Usaste tus ' . $u['allowed'] . ' subidas de HTML propio de este mes.')
        . ' Se reinician el ' . $when . '. Sube de plan para más.';
$myAlias = Creators::alias($uid);
$counts = Creators::counts($uid);
$okUp = static fn ($f): bool => is_array($f) && is_string($f['tmp_name'] ?? null) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Los archivos superan el tamaño máximo permitido. Sube menos fotos o más ligeras.');
        redirect('upload_html.php' . ($in['mode'] === 'publica' ? '?modo=publica' : ''));
    }
    csrf_verify();
    foreach (['page_name', 'tpl_name', 'description', 'category', 'photos', 'price', 'alias'] as $k) {
        $in[$k] = is_string($_POST[$k] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $_POST[$k])) : '';
    }
    $in['mode'] = ($_POST['mode'] ?? '') === 'publica' ? 'publica' : 'privada';
    $in['quota'] = !empty($_POST['quota']) ? '1' : '';
    $photos = preg_match('/^\d{1,2}$/', $in['photos']) === 1 ? (int) $in['photos'] : -1;
    if ($photos < 0 || $photos > Creators::MAX_PHOTOS) {
        $errors[] = 'El número de fotos debe estar entre 0 y ' . Creators::MAX_PHOTOS . '.';
    }
    $file = $_FILES['file'] ?? null;
    $tmp = null;
    $staged = ['staged' => [], 'errors' => [], 'dir' => null];
    try {
        if ($in['mode'] === 'privada') {
            // ------------------------------------------------------------------ privada
            $name = $in['page_name'];
            if ($tpl === null) {
                $errors[] = 'Esta función no está disponible ahora mismo.';
            }
            if ($name === '' || mb_strlen($name) > 60) {
                $errors[] = 'Escribe un nombre para la página (1 a 60 caracteres).';
            }
            if ($usage['remaining'] <= 0) {
                $errors[] = $quotaMsg($usage, $resetTxt);
            }
            $insp = null;
            if (!$errors) {
                if (!$okUp($file)) {
                    $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                    $errors[] = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        ? 'El archivo es demasiado grande (HTML hasta 512 KB, .zip hasta 5 MB).' : 'Elige un archivo .html o .zip para subir.';
                } else {
                    // Todo se valida y escanea ANTES de crear nada: un archivo rechazado no consume cupo ni deja archivos.
                    $insp = UserHtml::inspect($file['tmp_name'], (string) ($file['name'] ?? ''));
                    $errors = $insp['errors'];
                }
            }
            if (!$errors) {
                $staged = TemplateImages::stage(TemplateImages::parse(Creators::imageSpec($photos)), $_FILES);
                $ferr = $staged['errors'];
                $errors = array_values($ferr);
            }
            if (!$errors && $tpl !== null && $insp !== null) {
                $data = ['your_name' => $name, 'partner_name' => '', 'start_date' => date('Y-m-d'), 'message' => ''];
                $hasAssets = $insp['assets'] !== [];
                $commitPhotos = TemplateImages::committer($staged['staged']);
                $onCreated = static function (PDO $pdo, int $siteId, string $slug, array $fresh) use ($insp, $hasAssets, $quotaMsg, $commitPhotos): ?string {
                    $uploadId = Access::tryRecordHtmlUpload($pdo, $fresh, $insp['sha256'], $insp['bytes']);
                    if ($uploadId === null) {
                        $u = Access::htmlUploadUsage($fresh);
                        return $quotaMsg($u, Access::monthResetLabel($u['resets_at']));
                    }
                    try {
                        UserHtml::store($slug, $insp['html'], $insp['assets']);
                    } catch (Throwable $ex) {
                        error_log('HTML propio: no se pudo guardar ' . $slug . ': ' . $ex->getMessage());
                        Sites::purgeFiles($slug);
                        return 'No se pudo guardar tu página. Inténtalo de nuevo en unos minutos.';
                    }
                    $pdo->prepare('INSERT INTO user_html_sites (site_id, sha256, bytes, has_assets) VALUES (?, ?, ?, ?)')
                        ->execute([$siteId, $insp['sha256'], $insp['bytes'], $hasAssets ? 1 : 0]);
                    Access::linkHtmlUpload($pdo, $uploadId, $siteId);
                    return $commitPhotos($pdo, $siteId, $slug, $fresh);   // fotos reales de la página (site_images)
                };
                $res = Sites::create($user, $tpl, $data, $onCreated);
                if (isset($res['slug'])) {
                    flash('¡Tu HTML está publicado! Comparte el enlace.');
                    redirect('dashboard.php');
                }
                $errors[] = (string) ($res['error'] ?? 'No se pudo crear la página.');
                $usage = Access::htmlUploadUsage($user);
            }
        } else {
            // ------------------------------------------------------------------ pública
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
            $html = '';
            if (!$errors) {
                if ($fromSite !== null) {
                    $errors = $fromSite['errors'];
                    $html = $fromSite['html'];
                } elseif (!$okUp($file)) {
                    $errors[] = 'Elige el archivo .html de tu plantilla (hasta 512 KB).';
                } else {
                    $r = Creators::inspectUpload($file['tmp_name'], (string) ($file['name'] ?? ''));
                    $errors = $r['errors'];
                    $html = $r['html'];
                }
                if (!$okUp($_FILES['thumb'] ?? null)) {
                    $errors[] = 'La miniatura es obligatoria (JPG, PNG o WebP, hasta 5 MB).';
                }
            }
            if (!$errors) {
                $tmp = ImageStore::makeTempDir();
                $img = $tmp === null ? 'El servidor no puede procesar imágenes ahora mismo.' : ImageStore::thumbnail((string) $_FILES['thumb']['tmp_name'], $tmp, 800, 480);
                if (is_string($img)) {
                    $errors[] = $img;
                } else {
                    $res = Creators::submit($uid, ['name' => $in['tpl_name'], 'description' => $in['description'], 'category' => $in['category'],
                        'photos' => $photos, 'price' => $price, 'quota' => $in['quota'] === '1'], $html, $img['path'], $newAlias);
                    if ($res['errors'] === []) {
                        flash('¡Recibimos tu plantilla! La revisaremos y aparecerá en la Galería cuando la aprueben.');
                        redirect('creator.php');
                    }
                    $errors = $res['errors'];
                }
            }
        }
    } finally {
        ImageStore::removeTempDir($staged['dir']);
        ImageStore::removeTempDir($tmp);
    }
}

$tier = Access::userTier($user);
$pct = $usage['allowed'] > 0 ? min(100, (int) round($usage['used'] / $usage['allowed'] * 100)) : 100;
$full = $usage['remaining'] <= 0;
$card = 'rounded-2xl bg-white border border-rose-100 p-5';
$lbl = 'block text-sm font-semibold text-slate-700 mb-1';
$field = INPUT_CLS . ' min-h-[44px]';
$fileCls = 'block w-full text-sm min-h-[44px] rounded-xl border border-rose-200 bg-white p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:font-semibold file:text-rose-700';
$radio = 'flex items-start gap-3 rounded-2xl border-2 border-rose-100 bg-white p-4 cursor-pointer has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50';
$nPhotos = preg_match('/^\d{1,2}$/', $in['photos']) === 1 ? min(Creators::MAX_PHOTOS, (int) $in['photos']) : 0;

page_start('Subir mi plantilla', 'max-w-3xl');
?>
<style nonce="<?= e(csp_nonce()) ?>">
  #subir:has(#modo-privada:checked) .solo-publica{display:none}
  #subir:has(#modo-publica:checked) .solo-privada{display:none}
  .slot-off{display:none}
</style>
<section class="mt-2 mb-4">
  <h1 class="text-2xl font-bold">Subir mi plantilla</h1>
  <p class="mt-1 text-sm text-slate-600">Un archivo <strong>.html</strong> (o <strong>.zip</strong> con imágenes, CSS y JS). Tú decides si es solo tuya o si la compartes con todos.</p>
</section>

<form id="subir" method="post" enctype="multipart/form-data" class="space-y-4" novalidate>
  <?= csrf_field() ?>
  <?php if ($desde > 0): ?><input type="hidden" name="desde" value="<?= (int) $desde ?>"><?php endif; ?>
  <?php if ($errors): ?>
    <div id="form-errors" role="alert" class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm px-4 py-3">
      <p class="font-semibold">No pudimos enviar tu archivo:</p>
      <ul class="mt-1 list-disc pl-5 space-y-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <section class="<?= $card ?>" aria-labelledby="h-p1">
    <h2 id="h-p1" class="font-semibold"><span class="text-rose-500">1.</span> Tu archivo</h2>
    <?php if ($fromSite !== null && $fromSite['html'] !== ''): ?>
      <p class="mt-2 text-sm text-slate-600">Usaremos el HTML de tu página «<?= e($fromSite['name']) ?>». No hace falta subirlo otra vez; tu página privada no cambia.</p>
    <?php else: ?>
      <label class="<?= $lbl ?> mt-2" for="file">Archivo (.html, .htm o .zip)</label>
      <input id="file" name="file" type="file" accept=".html,.htm,.zip,text/html,application/zip" class="<?= $fileCls ?>" aria-describedby="file-help">
      <p id="file-help" class="mt-1 text-xs text-slate-500">HTML hasta 512 KB · ZIP hasta 5 MB (con <code>index.html</code> en la raíz; los .zip solo para uso privado).</p>
    <?php endif; ?>
  </section>

  <section class="<?= $card ?>" aria-labelledby="h-p2">
    <h2 id="h-p2" class="font-semibold"><span class="text-rose-500">2.</span> ¿Privada o pública?</h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-2" role="radiogroup">
      <label class="<?= $radio ?>"><input type="radio" id="modo-privada" name="mode" value="privada" class="mt-1 h-5 w-5 accent-rose-600"<?= $in['mode'] === 'privada' ? ' checked' : '' ?><?= $desde > 0 ? ' disabled' : '' ?>>
        <span><strong>Privada — solo yo la uso</strong><span class="block text-sm text-slate-600">Se publica al instante como tu página. Cuenta contra tu cupo de HTML propio: <strong><?= (int) $usage['used'] ?> de <?= (int) $usage['allowed'] ?> este mes</strong> (se reinicia el <?= e($resetTxt) ?>).</span></span></label>
      <label class="<?= $radio ?>"><input type="radio" id="modo-publica" name="mode" value="publica" class="mt-1 h-5 w-5 accent-rose-600"<?= $in['mode'] === 'publica' ? ' checked' : '' ?>>
        <span><strong>Pública — para todos</strong><span class="block text-sm text-slate-600">Se envía a revisión y aparece en la Galería. Ganas <strong><?= (int) $cfg['share_pct'] ?> %</strong> de las monedas cada vez que otra persona la use. <strong>No consume tu cupo de HTML propio.</strong></span></span></label>
    </div>
    <?php if ($desde > 0): ?><input type="hidden" name="mode" value="publica"><?php endif; ?>
    <?php if ($full): ?><p role="status" class="solo-privada mt-3 text-sm text-rose-800"><?= e($quotaMsg($usage, $resetTxt)) ?> <a class="font-semibold underline" href="<?= e(url('tienda.php#membresias')) ?>">Ver planes</a></p><?php endif; ?>

    <div class="solo-privada mt-4">
      <label class="<?= $lbl ?>" for="page_name">Nombre de la página</label>
      <input id="page_name" name="page_name" maxlength="60" class="<?= $field ?>" value="<?= e($in['page_name']) ?>" autocomplete="off">
    </div>

    <div class="solo-publica mt-4 space-y-4">
      <p class="text-xs text-slate-500">Pendientes: <?= (int) $counts['pending'] ?>/<?= (int) $cfg['max_pending'] ?> · Plantillas públicas: <?= (int) $counts['total'] ?>/<?= (int) $cfg['max_templates'] ?>. Debe usar marcadores (<code>{{your_name}}</code> <code>{{partner_name}}</code> <code>{{start_date}}</code> <code>{{days_together}}</code> <code>{{message}}</code>).</p>
      <div><label class="<?= $lbl ?>" for="tpl_name">Nombre de la plantilla (máx. 60)</label><input id="tpl_name" name="tpl_name" maxlength="60" class="<?= $field ?>" value="<?= e($in['tpl_name']) ?>" autocomplete="off"></div>
      <div><label class="<?= $lbl ?>" for="description">Descripción (máx. 200)</label><textarea id="description" name="description" maxlength="200" rows="2" class="<?= INPUT_CLS ?>"><?= e($in['description']) ?></textarea></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="<?= $lbl ?>" for="category">Categoría</label><select id="category" name="category" class="<?= $field ?>"><?php foreach (Template::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $in['category'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label class="<?= $lbl ?>" for="price">Precio (<?= (int) $cfg['min_price'] ?>–<?= (int) $cfg['max_price'] ?> monedas)</label><input id="price" name="price" type="number" inputmode="numeric" min="<?= (int) $cfg['min_price'] ?>" max="<?= (int) $cfg['max_price'] ?>" class="<?= $field ?>" value="<?= e($in['price']) ?>"></div>
      </div>
      <label class="flex items-start gap-3 min-h-[44px] text-sm"><input type="checkbox" name="quota" value="1" class="mt-1 h-5 w-5 accent-rose-600"<?= $in['quota'] === '1' ? ' checked' : '' ?>><span>Incluir en el cupo mensual de membresías <span class="text-slate-500">(sin marcar, solo monedas)</span></span></label>
      <div><label class="<?= $lbl ?>" for="thumb">Miniatura (JPG, PNG o WebP; se recorta a 800×480)</label><input id="thumb" name="thumb" type="file" accept="image/jpeg,image/png,image/webp" class="<?= $fileCls ?>"></div>
      <?php if ($myAlias === null): ?>
        <div><label class="<?= $lbl ?>" for="alias">Tu alias público (se muestra como autor)</label><input id="alias" name="alias" minlength="<?= Ranking::ALIAS_MIN ?>" maxlength="<?= Ranking::ALIAS_MAX ?>" class="<?= $field ?>" value="<?= e($in['alias']) ?>" autocomplete="nickname"><p class="mt-1 text-xs text-slate-500">Elegirlo ahora es gratis y no te muestra en el Top de donadores.</p></div>
      <?php else: ?>
        <p class="text-sm text-slate-600">Autor mostrado: <strong><?= e($myAlias) ?></strong> (<a class="underline text-rose-700" href="<?= e(url('profile.php#alias')) ?>">tu alias público</a>).</p>
      <?php endif; ?>
      <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="consent" value="1" class="mt-1 h-5 w-5 accent-rose-600">
        <span>Soy autor o tengo derechos sobre esta plantilla, no contiene datos personales ni contenido de terceros y acepto que mi alias se muestre como autor y las <a class="underline text-rose-700" href="<?= e(url('terms.php#creadores')) ?>" target="_blank" rel="noopener">condiciones del programa de creadores</a>.</span></label>
    </div>
  </section>

  <section class="<?= $card ?>" aria-labelledby="h-p3">
    <h2 id="h-p3" class="font-semibold"><span class="text-rose-500">3.</span> Fotos</h2>
    <label class="<?= $lbl ?> mt-2" for="photos">¿Tu HTML usa fotos? ¿Cuántas? (0–<?= Creators::MAX_PHOTOS ?>)</label>
    <input id="photos" name="photos" type="number" min="0" max="<?= Creators::MAX_PHOTOS ?>" class="<?= $field ?> max-w-[8rem]" value="<?= e((string) $nPhotos) ?>" data-photo-count>
    <p class="mt-2 text-xs text-slate-500">Coloca en tu HTML los marcadores <code>{{img_foto_1}}</code>, <code>{{img_foto_2}}</code>… (por ejemplo <code>&lt;img src="{{img_foto_1}}"&gt;</code>). Para mostrar algo solo si hay foto: <code>{{#if img_foto_1}}…{{/if}}</code>.</p>
    <p class="solo-publica mt-2 text-sm text-slate-600">En una plantilla pública, quien la use subirá sus propias fotos al crear su página; aquí solo defines cuántas.</p>
    <div class="solo-privada mt-3 space-y-3" data-slots>
      <?php for ($i = 1; $i <= Creators::MAX_PHOTOS; $i++): $k = 'img_foto_' . $i; ?>
        <div data-slot="<?= $i ?>">
          <label class="<?= $lbl ?>" for="<?= $k ?>">Foto <?= $i ?> <span class="font-normal text-slate-500">(opcional · JPG, PNG o WebP, hasta 5 MB)</span></label>
          <input id="<?= $k ?>" name="<?= $k ?>" type="file" accept="image/jpeg,image/png,image/webp" class="<?= $fileCls ?>">
          <img alt="" class="hidden mt-2 h-24 rounded-lg" data-prev>
          <p class="text-sm text-rose-700" data-warn hidden>Esta foto pesa más de 5 MB.</p>
          <?php if (isset($ferr[$k])): ?><p role="alert" class="text-sm text-rose-700"><?= e($ferr[$k]) ?></p><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </section>

  <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2">Enviar</button>
  <p class="text-xs text-slate-500">Tu HTML se muestra en un espacio aislado: sin acceso a tu cuenta ni a cookies. Al subir aceptas los <a class="underline text-rose-700" href="<?= e(url('terms.php#html-propio')) ?>">términos</a>. Prohibido: formularios, contraseñas, redirecciones, red, iframes, malware o contenido ilegal u ofensivo.</p>
</form>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= e(url('assets/js/upload.js')) ?>"></script>
<?php page_end();
