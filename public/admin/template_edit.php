<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Edición de una plantilla: metadatos, estado y reemplazo del .html / miniatura. */
Admin::guard();
$pdo = db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM templates WHERE id = ? AND kind NOT IN ('user', 'utpl')");
$st->execute([$id]);
$tpl = $st->fetch();

if (!$tpl) {
    http_response_code(404);
    flash('Plantilla no encontrada.');
    redirect('admin/templates.php');
}

$errors = [];
$form = [
    'name'        => (string) $tpl['name'],
    'description' => (string) $tpl['description'],
    'price_usd'   => number_format((float) $tpl['price_usd'], 2, '.', ''),
    'price_coins' => (string) (int) $tpl['price_coins'],
    'is_premium'  => (string) (int) $tpl['is_premium'],
    'membership_unlocks' => (string) (int) $tpl['membership_unlocks'],
    'is_active'   => (string) (int) $tpl['is_active'],
    'category'    => (string) $tpl['category'],
    'image_spec'  => (string) ($tpl['image_spec'] ?? ''),
];
$isPhp = ($tpl['kind'] ?? 'html') === 'php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();

    $form['name']        = trim((string) ($_POST['name'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['price_usd']   = (string) ($_POST['price_usd'] ?? '0');
    $form['price_coins'] = (string) ($_POST['price_coins'] ?? '0');
    $form['is_premium']  = !empty($_POST['is_premium']) ? '1' : '0';
    $form['membership_unlocks'] = !empty($_POST['membership_unlocks']) ? '1' : '0';
    $form['is_active']   = !empty($_POST['is_active']) ? '1' : '0';
    $form['category']    = array_key_exists((string) ($_POST['category'] ?? ''), Template::CATEGORIES) ? (string) $_POST['category'] : (string) $tpl['category'];

    if ($form['name'] === '' || mb_strlen($form['name']) > 60) {
        $errors[] = 'El nombre es obligatorio (máximo 60 caracteres).';
    }
    if (mb_strlen($form['description']) > 200) {
        $errors[] = 'La descripción no puede superar 200 caracteres.';
    }
    $price = Admin::parsePriceUsd($form['price_usd']);
    if ($price === null) {
        $errors[] = 'El precio debe ser un monto en USD, hasta 999.99 con 2 decimales (0 = sin compra individual).';
    }

    $coins = filter_var($form['price_coins'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
    if ($coins === false) {
        $errors[] = 'El costo en monedas debe ser un entero entre 0 y 100000.';
    }

    // Aviso, no bloqueo: desactivar una plantilla en uso deja esas páginas en 404.
    $inUse = Admin::siteCount($id);
    if ($form['is_active'] === '0' && $inUse > 0 && empty($_POST['confirm_break'])) {
        $errors[] = "$inUse página(s) usan esta plantilla y dejarán de mostrarse. Marca la confirmación para continuar.";
    }

    // Reemplazo del contenido (opcional): .html con el mismo rasero que una alta, o .zip para plantillas PHP.
    $newHtml = null;
    $newZip = null;
    $file = $_FILES[$isPhp ? 'bundle' : 'html'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($err = Admin::uploadError($file)) {
            $errors[] = $err;
        } elseif ($isPhp) {
            if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) === 'zip') {
                $newZip = (string) $file['tmp_name'];
            } else {
                $errors[] = 'La plantilla PHP debe subirse como archivo .zip.';
            }
        } else {
            $newHtml = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, Admin::MAX_TEMPLATE_BYTES + 1);
            $errors = array_merge($errors, Admin::validateTemplateHtml($newHtml));
        }
    }
    // Fotos que pide: en HTML, el textarea; en PHP, `images` del manifest.json del zip nuevo (si trae manifest).
    $imageSpec = ($tpl['image_spec'] ?? null) === null ? null : (string) $tpl['image_spec'];
    if ($isPhp) {
        $manifest = $newZip !== null ? PhpTemplate::manifestFromZip($newZip) : null;
        if ($manifest !== null) {
            $imageSpec = TemplateImages::specFromManifest($manifest, $specErr);
            if ($specErr !== null) {
                $errors[] = $specErr;
            }
        }
    } else {
        $form['image_spec'] = trim((string) ($_POST['image_spec'] ?? ''));
        if ($specErr = TemplateImages::validate($form['image_spec'])) {
            $errors[] = 'Fotos que pide: ' . $specErr;
        }
        $imageSpec = $form['image_spec'] === '' ? null : $form['image_spec'];
    }
    if (!$errors && $newZip !== null) {
        $errors = PhpTemplate::install((string) $tpl['slug'], $newZip);   // reemplazo atómico; no toca nada si falla
    }

    // Miniatura: reemplazo o borrado.
    $thumb = $tpl['thumbnail'];
    if (!$errors) {
        [$newThumb, $thumbErr] = Admin::storeThumbnail($_FILES['thumbnail'] ?? ['error' => UPLOAD_ERR_NO_FILE], (string) $tpl['slug']);
        if ($thumbErr !== null) {
            $errors[] = $thumbErr;
        } elseif ($newThumb !== null) {
            Admin::deleteThumbnail($tpl['thumbnail']);
            $thumb = $newThumb;
        } elseif (!empty($_POST['remove_thumbnail'])) {
            Admin::deleteThumbnail($tpl['thumbnail']);
            $thumb = null;
        }
    }

    if (!$errors) {
        try {
            if ($newHtml !== null) {
                Admin::writeTemplateFile((string) $tpl['file'], $newHtml);
            }
            $pdo->prepare('UPDATE templates SET name = ?, description = ?, price_usd = ?, price_coins = ?, category = ?, thumbnail = ?, is_premium = ?, membership_unlocks = ?, is_active = ?, image_spec = ? WHERE id = ?')
                ->execute([
                    $form['name'], $form['description'], (string) $price, (int) $coins, $form['category'], $thumb,
                    (int) $form['is_premium'], (int) $form['membership_unlocks'], (int) $form['is_active'], $imageSpec, $id,
                ]);
            Admin::log('template.update', $tpl['slug'] . ($newHtml !== null ? ' (html reemplazado)' : '') . ($newZip !== null ? ' (bundle php reemplazado)' : ''));
            flash("Plantilla «{$form['name']}» actualizada.");
            redirect('admin/templates.php');
        } catch (Throwable $ex) {
            error_log('admin template update: ' . $ex->getMessage());
            $errors[] = 'No se pudo guardar la plantilla.';
        }
    }
}

$inUse   = Admin::siteCount($id);
$path    = $isPhp ? PhpTemplate::baseDir() . '/' . $tpl['slug'] . '/index.php' : Admin::templateDir() . '/' . $tpl['file'];
$size    = is_file($path) ? round(filesize($path) / 1024, 1) . ' KiB' : 'archivo ausente';

admin_page_start('Editar: ' . $tpl['name'], 'templates', 'Metadatos, estado y archivos de la plantilla.', '<a href="' . e(url('admin/templates.php')) . '" class="' . ADMIN_BTN_GHOST_CLS . '">← Plantillas</a>');
admin_errors($errors);
?>
<section class="<?= ADMIN_CARD_CLS ?>">
  <p class="text-xs text-slate-500 mb-4">
    Identificador <code><?= e($tpl['slug']) ?></code> · archivo <code><?= e($tpl['file']) ?></code> (<?= e($size) ?>) ·
    <?= (int) $inUse ?> página(s) en uso ·
    <a class="text-slate-700 underline" href="<?= e(url('admin/template_preview.php?id=' . $id)) ?>" target="_blank" rel="noopener">ver ejemplo</a>
  </p>

  <form method="post" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div>
      <label class="block text-sm font-semibold mb-1" for="name">Nombre</label>
      <input id="name" name="name" class="<?= ADMIN_INPUT_CLS ?>" maxlength="60" required value="<?= e($form['name']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="price_usd">Precio individual (USD)</label>
      <input id="price_usd" name="price_usd" type="number" min="0" max="999.99" step="0.01" inputmode="decimal" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_usd']) ?>">
      <p class="text-xs text-slate-500 mt-1">0 = solo con membresía. Mayor que 0 = también se puede comprar suelta.</p>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="price_coins">Costo en monedas</label>
      <input id="price_coins" name="price_coins" type="number" min="0" max="100000" step="1" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_coins']) ?>">
      <p class="text-xs text-slate-500 mt-1">Se descuenta al crear (y renovar) una página. 0 = gratis. Con «Premium» marcado, es el precio para quien no tenga cupo o membresía.</p>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="category">Categoría</label>
      <select id="category" name="category" class="<?= ADMIN_INPUT_CLS ?>">
        <?php foreach (Template::CATEGORIES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $form['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-semibold mb-1" for="description">Descripción</label>
      <input id="description" name="description" class="<?= ADMIN_INPUT_CLS ?>" maxlength="200" value="<?= e($form['description']) ?>">
    </div>

    <div class="sm:col-span-2 flex flex-wrap gap-6 text-sm font-semibold">
      <label class="flex items-center gap-2">
        <input type="checkbox" name="is_premium" value="1" <?= $form['is_premium'] === '1' ? 'checked' : '' ?>> Premium (de paga)
      </label>
      <label class="flex items-center gap-2">
        <input type="checkbox" name="membership_unlocks" value="1" <?= $form['membership_unlocks'] === '1' ? 'checked' : '' ?>> Cupo mensual de membresía
      </label>
      <label class="flex items-center gap-2">
        <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?>> Activa
      </label>
      <?php if ($inUse > 0): ?>
        <label class="flex items-center gap-2 text-rose-700 font-normal">
          <input type="checkbox" name="confirm_break" value="1"> Entiendo que desactivarla rompe <?= (int) $inUse ?> página(s)
        </label>
      <?php endif; ?>
    </div>
    <?php if ($form['is_premium'] === '1'): ?>
      <p class="sm:col-span-2 text-xs text-slate-500 -mt-2">Con «Cupo mensual» marcado, cada plan solo la desbloquea gratis hasta agotar su cupo de plantillas del mes (ajustable en <code>membership_tiers</code>); agotado, se usa pagando el costo en monedas. Sin marcar, la membresía la desbloquea sin límite (como antes).</p>
    <?php endif; ?>

    <div>
      <?php if ($isPhp): ?>
        <label class="block text-sm font-semibold mb-1" for="bundle">Reemplazar carpeta PHP (.zip) <span class="font-normal text-slate-500">(opcional)</span></label>
        <input id="bundle" name="bundle" type="file" accept=".zip,application/zip" class="<?= ADMIN_INPUT_CLS ?>">
      <?php else: ?>
        <label class="block text-sm font-semibold mb-1" for="html">Reemplazar HTML <span class="font-normal text-slate-500">(opcional)</span></label>
        <input id="html" name="html" type="file" accept=".html,text/html" class="<?= ADMIN_INPUT_CLS ?>">
      <?php endif; ?>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="thumbnail">Miniatura</label>
      <input id="thumbnail" name="thumbnail" type="file" accept="image/png,image/jpeg,image/webp" class="<?= ADMIN_INPUT_CLS ?>">
      <?php if ($tpl['thumbnail']): ?>
        <label class="flex items-center gap-2 text-xs text-slate-500 mt-2">
          <input type="checkbox" name="remove_thumbnail" value="1"> Quitar la miniatura actual
          <img src="<?= e(url('assets/thumbs/' . $tpl['thumbnail'])) ?>" alt="" width="32" height="32" class="w-8 h-8 rounded object-cover border border-slate-200">
        </label>
      <?php endif; ?>
    </div>

    <?php if (!$isPhp): ?>
    <div class="sm:col-span-2">
      <label class="block text-sm font-semibold mb-1" for="image_spec">Fotos que pide (JSON) <span class="font-normal text-slate-500">(opcional)</span></label>
      <textarea id="image_spec" name="image_spec" rows="5" spellcheck="false" class="<?= ADMIN_INPUT_CLS ?> font-mono text-xs"><?= e($form['image_spec']) ?></textarea>
      <p class="text-xs text-slate-500 mt-1">Marcadores: <code>{{img_&lt;clave&gt;}}</code> y <code>{{img_count}}</code>. Ver docs/IMAGENES.md.</p>
    </div>
    <?php else: ?>
      <p class="sm:col-span-2 text-xs text-slate-500">Fotos que pide: se leen de <code>manifest.json</code> (clave <code>images</code>) al reemplazar el .zip. Actual: <code><?= e($form['image_spec'] === '' ? 'ninguna' : $form['image_spec']) ?></code></p>
    <?php endif; ?>

    <div class="sm:col-span-2"><button class="<?= ADMIN_BTN_CLS ?>">Guardar cambios</button></div>
  </form>
</section>
<?php admin_page_end();
