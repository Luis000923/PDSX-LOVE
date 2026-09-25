<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Edición de una plantilla: metadatos, estado y reemplazo del .html / miniatura. */
Admin::guard();
$pdo = db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
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
    'price_cop'   => (string) $tpl['price_cop'],
    'is_premium'  => (string) (int) $tpl['is_premium'],
    'is_active'   => (string) (int) $tpl['is_active'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();

    $form['name']        = trim((string) ($_POST['name'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['price_cop']   = (string) ($_POST['price_cop'] ?? '0');
    $form['is_premium']  = !empty($_POST['is_premium']) ? '1' : '0';
    $form['is_active']   = !empty($_POST['is_active']) ? '1' : '0';

    if ($form['name'] === '' || mb_strlen($form['name']) > 60) {
        $errors[] = 'El nombre es obligatorio (máximo 60 caracteres).';
    }
    if (mb_strlen($form['description']) > 200) {
        $errors[] = 'La descripción no puede superar 200 caracteres.';
    }
    if (!ctype_digit($form['price_cop']) || (int) $form['price_cop'] > 9999999) {
        $errors[] = 'El precio debe ser un número entero de pesos (0 = gratis).';
    }

    // Aviso, no bloqueo: desactivar una plantilla en uso deja esas páginas en 404.
    $inUse = Admin::siteCount($id);
    if ($form['is_active'] === '0' && $inUse > 0 && empty($_POST['confirm_break'])) {
        $errors[] = "$inUse página(s) usan esta plantilla y dejarán de mostrarse. Marca la confirmación para continuar.";
    }

    // Reemplazo del .html (opcional): se valida con el mismo rasero que una alta.
    $newHtml = null;
    $file = $_FILES['html'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($err = Admin::uploadError($file)) {
            $errors[] = $err;
        } else {
            $newHtml = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, Admin::MAX_TEMPLATE_BYTES + 1);
            $errors = array_merge($errors, Admin::validateTemplateHtml($newHtml));
        }
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
            $pdo->prepare('UPDATE templates SET name = ?, description = ?, price_cop = ?, thumbnail = ?, is_premium = ?, is_active = ? WHERE id = ?')
                ->execute([
                    $form['name'], $form['description'], (int) $form['price_cop'], $thumb,
                    (int) $form['is_premium'], (int) $form['is_active'], $id,
                ]);
            Admin::log('template.update', $tpl['slug'] . ($newHtml !== null ? ' (html reemplazado)' : ''));
            flash("Plantilla «{$form['name']}» actualizada.");
            redirect('admin/templates.php');
        } catch (Throwable $ex) {
            error_log('admin template update: ' . $ex->getMessage());
            $errors[] = 'No se pudo guardar la plantilla.';
        }
    }
}

$inUse   = Admin::siteCount($id);
$path    = Admin::templateDir() . '/' . $tpl['file'];
$size    = is_file($path) ? round(filesize($path) / 1024, 1) . ' KiB' : 'archivo ausente';

admin_page_start('Editar plantilla', 'templates');
admin_errors($errors);
?>
<div class="flex items-center gap-3 mb-6">
  <a href="<?= e(url('admin/templates.php')) ?>" class="text-sm text-slate-500 hover:text-slate-800">← Plantillas</a>
  <h1 class="text-2xl font-bold"><?= e($tpl['name']) ?></h1>
</div>

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
      <label class="block text-sm font-semibold mb-1" for="price_cop">Precio de referencia (COP)</label>
      <input id="price_cop" name="price_cop" type="number" min="0" max="9999999" step="1" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_cop']) ?>">
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
        <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?>> Activa
      </label>
      <?php if ($inUse > 0): ?>
        <label class="flex items-center gap-2 text-rose-700 font-normal">
          <input type="checkbox" name="confirm_break" value="1"> Entiendo que desactivarla rompe <?= (int) $inUse ?> página(s)
        </label>
      <?php endif; ?>
    </div>

    <div>
      <label class="block text-sm font-semibold mb-1" for="html">Reemplazar HTML <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="html" name="html" type="file" accept=".html,text/html" class="<?= ADMIN_INPUT_CLS ?>">
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

    <div class="sm:col-span-2"><button class="<?= ADMIN_BTN_CLS ?>">Guardar cambios</button></div>
  </form>
</section>
<?php admin_page_end();
