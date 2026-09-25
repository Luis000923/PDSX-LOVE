<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/HtmlScanner.php';
require_once ROOT . '/src/UserHtml.php';

/**
 * Sube tu propio HTML (.html o .zip con recursos). Cupo mensual por plan, escaneo de seguridad y publicación directa.
 * El documento se sirve aislado (ver public/frame.php y docs/HTML_PROPIO.md).
 */
$user = require_login();
$pdo  = db();
$errors = [];
$name = '';
$insp = null;

$tplSt = $pdo->prepare("SELECT id, slug, name, kind, file, price_usd, price_coins, is_premium, membership_unlocks FROM templates WHERE slug = 'html-propio' AND kind = 'user'");
$tplSt->execute();
$tpl = $tplSt->fetch() ?: null;

$usage = Access::htmlUploadUsage($user);
$resetTxt = Access::monthResetLabel($usage['resets_at']);
$quotaMsg = static fn (array $u, string $when): string
    => ($u['allowed'] === 1 ? 'Usaste tu subida de HTML propio de este mes.' : 'Usaste tus ' . $u['allowed'] . ' subidas de HTML propio de este mes.')
        . ' Se reinician el ' . $when . '. Sube de plan para más.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) ($_POST['page_name'] ?? '')));
    $file = $_FILES['file'] ?? null;

    if ($tpl === null) {
        $errors[] = 'Esta función no está disponible ahora mismo.';
    }
    if ($name === '' || mb_strlen($name) > 60) {
        $errors[] = 'Escribe un nombre para la página (1 a 60 caracteres).';
    }
    if ($usage['remaining'] <= 0) {
        $errors[] = $quotaMsg($usage, $resetTxt);
    }
    if (!$errors) {
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            $errors[] = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo es demasiado grande (HTML hasta 512 KB, .zip hasta 5 MB).'
                : 'Elige un archivo .html o .zip para subir.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'No se pudo procesar la subida.';
        } else {
            // Todo se valida y escanea ANTES de crear nada: un archivo rechazado no consume cupo ni deja archivos.
            $insp = UserHtml::inspect($file['tmp_name'], (string) ($file['name'] ?? ''));
            $errors = $insp['errors'];
        }
    }

    if (!$errors && $tpl !== null && $insp !== null) {
        $data = ['your_name' => $name, 'partner_name' => '', 'start_date' => date('Y-m-d'), 'message' => ''];
        $hasAssets = $insp['assets'] !== [];
        $onCreated = static function (PDO $pdo, int $siteId, string $slug, array $fresh) use ($insp, $hasAssets, $quotaMsg): ?string {
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
            return null;
        };
        $res = Sites::create($user, $tpl, $data, $onCreated);
        if (isset($res['slug'])) {
            flash('¡Tu HTML está publicado! Comparte el enlace.');
            redirect('dashboard.php');
        }
        $errors[] = (string) ($res['error'] ?? 'No se pudo crear la página.');
        $usage = Access::htmlUploadUsage($user);
    }
}

$tier = Access::userTier($user);
$pct = $usage['allowed'] > 0 ? min(100, (int) round($usage['used'] / $usage['allowed'] * 100)) : 100;
$full = $usage['remaining'] <= 0;
$card = 'rounded-2xl bg-white border border-rose-100 p-5';

page_start('Sube tu propio HTML', 'max-w-3xl');
?>
<section class="mt-2 mb-4">
  <h1 class="text-2xl font-bold">Sube tu propio HTML</h1>
  <p class="mt-1 text-sm text-slate-600">Publica tu diseño como una página de LovePages. Un archivo <strong>.html</strong> o un <strong>.zip</strong> con tus imágenes, CSS y JS. Se revisa automáticamente y se publica al instante.</p>
</section>

<p class="mb-4 rounded-2xl bg-white border border-rose-100 p-4 text-sm text-slate-600">¿Quieres publicarla para todos? Usa <a class="font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('creator_upload.php')) ?>">Publicar en la Galería</a> (no gasta tu cupo de HTML propio y ganas monedas cuando otras personas la usen).</p>

<section class="<?= $card ?>" aria-labelledby="h-cupo">
  <h2 id="h-cupo" class="font-semibold">Tu cupo de este mes</h2>
  <p class="mt-1 text-sm <?= $full ? 'font-semibold text-rose-800' : 'text-slate-600' ?>"><?= (int) $usage['used'] ?> de <?= (int) $usage['allowed'] ?> subidas · Plan <?= $tier ? e((string) $tier['name']) : 'gratuito' ?> · se reinicia el <?= e($resetTxt) ?></p>
  <div class="mt-2 h-2 rounded-full bg-rose-100 overflow-hidden" role="progressbar" aria-label="Subidas de HTML propio usadas este mes" aria-valuemin="0" aria-valuemax="<?= (int) $usage['allowed'] ?>" aria-valuenow="<?= (int) $usage['used'] ?>" aria-valuetext="<?= e($usage['used'] . ' de ' . $usage['allowed']) ?>"><div class="h-full <?= $full ? 'bg-rose-700' : 'bg-rose-500' ?>" style="width:<?= $pct ?>%"></div></div>
  <?php if ($full): ?><p role="status" class="mt-2 text-sm text-rose-800"><?= e($quotaMsg($usage, $resetTxt)) ?> <a class="font-semibold underline" href="<?= e(url('tienda.php#membresias')) ?>">Ver planes</a></p><?php endif; ?>
  <p class="mt-2 text-xs text-slate-500">La página creada también cuenta en el límite y la vigencia de páginas de tu plan.</p>
</section>

<form method="post" enctype="multipart/form-data" class="mt-4 <?= $card ?> space-y-4" aria-describedby="<?= $errors ? 'form-errors' : 'form-help' ?>">
  <?= csrf_field() ?>
  <?php if ($errors): ?>
    <div id="form-errors" role="alert" class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm px-4 py-3">
      <p class="font-semibold">No pudimos publicar tu archivo:</p>
      <ul class="mt-1 list-disc pl-5 space-y-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <div>
    <label class="block text-sm font-semibold text-slate-700 mb-1" for="page_name">Nombre de la página</label>
    <input id="page_name" name="page_name" type="text" required maxlength="60" class="<?= INPUT_CLS ?> min-h-[44px]" value="<?= e($name) ?>" autocomplete="off">
  </div>
  <div>
    <label class="block text-sm font-semibold text-slate-700 mb-1" for="file">Archivo (.html, .htm o .zip)</label>
    <input id="file" name="file" type="file" required accept=".html,.htm,.zip,text/html,application/zip" class="block w-full text-sm min-h-[44px] rounded-xl border border-rose-200 bg-white p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:font-semibold file:text-rose-700">
    <p id="form-help" class="mt-1 text-xs text-slate-500">HTML hasta 512 KB · ZIP hasta 5 MB (8 MB descomprimido, 60 archivos, con <code>index.html</code> en la raíz).</p>
  </div>
  <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"<?= $full ? ' disabled aria-disabled="true"' : '' ?>>Subir y publicar</button>
</form>

<section class="mt-4 grid gap-4 sm:grid-cols-2" aria-label="Reglas">
  <div class="<?= $card ?>">
    <h2 class="font-semibold text-emerald-700">Permitido</h2>
    <ul class="mt-2 text-sm text-slate-700 list-disc pl-5 space-y-1">
      <li>HTML, CSS y JavaScript propios para animaciones y efectos.</li>
      <li>Tailwind, jsDelivr y cdnjs; Google Fonts.</li>
      <li>En el .zip: png, jpg, webp, gif, svg (sin scripts), woff, woff2 y txt.</li>
      <li>Imágenes de internet (https) y en <code>data:</code>.</li>
    </ul>
  </div>
  <div class="<?= $card ?>">
    <h2 class="font-semibold text-rose-700">Prohibido</h2>
    <ul class="mt-2 text-sm text-slate-700 list-disc pl-5 space-y-1">
      <li>Formularios, campos de contraseña y cualquier intento de pedir datos.</li>
      <li>Redirigir, abrir ventanas, red (<code>fetch</code>), cookies y almacenamiento.</li>
      <li>Iframes, embeds, <code>eval</code>, código ofuscado y mineros.</li>
      <li>Malware, phishing, suplantación o contenido ilegal u ofensivo.</li>
    </ul>
  </div>
</section>
<p class="mt-4 text-xs text-slate-500">Tu página se muestra en un espacio aislado: no accede a tu cuenta ni a cookies, y sus enlaces externos y ventanas emergentes no funcionan. Al subir aceptas los <a class="underline text-rose-700" href="<?= e(url('terms.php#html-propio')) ?>">términos</a>. Podemos retirar páginas que incumplan las reglas.</p>
<?php page_end();
