<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Gestor de plantillas: listado, alta (subida de .html) y acciones rápidas. */
Admin::guard();
$pdo = db();

$errors = [];
$form   = ['name' => '', 'slug' => '', 'description' => '', 'price_cop' => '0', 'is_premium' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();                                   // 405 si no es POST + CSRF obligatorio
    $action = (string) ($_POST['action'] ?? '');

    // ---- Acciones rápidas sobre una plantilla existente -------------------
    if (in_array($action, ['toggle_premium', 'toggle_active', 'delete'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
        $st->execute([$id]);
        $tpl = $st->fetch();

        if (!$tpl) {
            flash('La plantilla ya no existe.');
        } elseif ($action === 'toggle_premium') {
            $new = (int) $tpl['is_premium'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE templates SET is_premium = ? WHERE id = ?')->execute([$new, $id]);
            Admin::log('template.premium', "{$tpl['slug']} -> " . ($new ? 'premium' : 'gratis'));
            flash("«{$tpl['name']}» ahora es " . ($new ? 'Premium.' : 'gratuita.'));
        } elseif ($action === 'toggle_active') {
            $new = (int) $tpl['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE templates SET is_active = ? WHERE id = ?')->execute([$new, $id]);
            Admin::log('template.active', "{$tpl['slug']} -> " . ($new ? 'activa' : 'inactiva'));
            flash("«{$tpl['name']}» " . ($new ? 'activada.' : 'desactivada (deja de ofrecerse, las páginas existentes dejan de renderizarse).'));
        } else {
            // Borrado: nunca si hay páginas que dependen de ella.
            $used = Admin::siteCount($id);
            if ($used > 0) {
                flash("No se puede eliminar «{$tpl['name']}»: $used página(s) la usan. Desactívala en su lugar.");
            } else {
                $pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$id]);

                // El .html solo se borra si ninguna otra fila lo referencia.
                if (!empty($_POST['delete_file'])) {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM templates WHERE file = ?');
                    $st->execute([$tpl['file']]);
                    if ((int) $st->fetchColumn() === 0 && preg_match('/^[a-z0-9-]+\.html$/', (string) $tpl['file'])) {
                        @unlink(Admin::templateDir() . '/' . $tpl['file']);
                    }
                }
                Admin::deleteThumbnail($tpl['thumbnail']);
                Admin::log('template.delete', (string) $tpl['slug']);
                flash("Plantilla «{$tpl['name']}» eliminada.");
            }
        }
        redirect('admin/templates.php');
    }

    // ---- Alta de plantilla ------------------------------------------------
    if ($action === 'create') {
        $form['name']        = trim((string) ($_POST['name'] ?? ''));
        $form['slug']        = Admin::slugify((string) ($_POST['slug'] ?? '') ?: $form['name']);
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['price_cop']   = (string) ($_POST['price_cop'] ?? '0');
        $form['is_premium']  = !empty($_POST['is_premium']) ? '1' : '0';

        if ($form['name'] === '' || mb_strlen($form['name']) > 60) {
            $errors[] = 'El nombre es obligatorio (máximo 60 caracteres).';
        }
        if (!preg_match('/^[a-z0-9-]{3,40}$/', $form['slug'])) {
            $errors[] = 'El identificador debe tener entre 3 y 40 caracteres [a-z0-9-].';
        }
        if (mb_strlen($form['description']) > 200) {
            $errors[] = 'La descripción no puede superar 200 caracteres.';
        }
        if (!ctype_digit($form['price_cop']) || (int) $form['price_cop'] > 9999999) {
            $errors[] = 'El precio debe ser un número entero de pesos (0 = gratis).';
        }

        $html = '';
        $file = $_FILES['html'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if ($err = Admin::uploadError($file)) {
            $errors[] = $err;
        } else {
            $html = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, Admin::MAX_TEMPLATE_BYTES + 1);
            $errors = array_merge($errors, Admin::validateTemplateHtml($html));
        }

        if (!$errors) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM templates WHERE slug = ?');
            $st->execute([$form['slug']]);
            if ((int) $st->fetchColumn() > 0) {
                $errors[] = 'Ya existe una plantilla con ese identificador.';
            } elseif (is_file(Admin::templateDir() . '/' . $form['slug'] . '.html')) {
                $errors[] = 'Ya hay un archivo con ese nombre en /templates. Usa otro identificador.';
            }
        }

        $thumb = null;
        if (!$errors) {
            [$thumb, $thumbErr] = Admin::storeThumbnail($_FILES['thumbnail'] ?? ['error' => UPLOAD_ERR_NO_FILE], $form['slug']);
            if ($thumbErr !== null) {
                $errors[] = $thumbErr;
            }
        }

        if (!$errors) {
            try {
                Admin::writeTemplateFile($form['slug'] . '.html', $html);
                $pdo->prepare('INSERT INTO templates (slug, name, file, description, price_cop, thumbnail, is_premium, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                    ->execute([
                        $form['slug'], $form['name'], $form['slug'] . '.html',
                        $form['description'], (int) $form['price_cop'], $thumb, (int) $form['is_premium'],
                    ]);
                Admin::log('template.create', $form['slug']);
                flash("Plantilla «{$form['name']}» creada.");
                redirect('admin/templates.php');
            } catch (Throwable $ex) {
                error_log('admin template create: ' . $ex->getMessage());
                Admin::deleteThumbnail($thumb);
                $errors[] = 'No se pudo guardar la plantilla.';
            }
        }
    }
}

$templates = $pdo->query(
    'SELECT t.*, (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id) AS sites
       FROM templates t ORDER BY t.is_premium, t.id'
)->fetchAll();

$vars = implode(', ', array_map(static fn(string $f): string => '{{' . $f . '}}', array_merge(array_keys(Template::FIELDS), ['days_together'])));

admin_page_start('Plantillas', 'templates');
admin_errors($errors);
?>
<h1 class="text-2xl font-bold mb-6">Plantillas</h1>

<section class="<?= ADMIN_CARD_CLS ?> overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-xs uppercase text-slate-500">
      <th class="pb-2">Plantilla</th><th class="pb-2">Tipo</th><th class="pb-2">Precio</th>
      <th class="pb-2">En uso</th><th class="pb-2">Estado</th><th class="pb-2 text-right">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr class="border-t border-slate-100 align-top">
        <td class="py-3">
          <div class="flex gap-3">
            <?php if ($t['thumbnail']): ?>
              <img src="<?= e(url('assets/thumbs/' . $t['thumbnail'])) ?>" alt="" width="48" height="48" class="w-12 h-12 rounded object-cover border border-slate-200">
            <?php endif; ?>
            <div>
              <p class="font-semibold"><?= e($t['name']) ?></p>
              <p class="text-xs text-slate-500"><?= e($t['slug']) ?> · <?= e($t['file']) ?></p>
              <?php if ($t['description']): ?><p class="text-xs text-slate-500 mt-1 max-w-sm"><?= e($t['description']) ?></p><?php endif; ?>
            </div>
          </div>
        </td>
        <td class="py-3 <?= $t['is_premium'] ? 'text-amber-600' : 'text-emerald-600' ?> font-semibold"><?= $t['is_premium'] ? 'Premium' : 'Gratis' ?></td>
        <td class="py-3"><?= $t['price_cop'] ? '$' . number_format((int) $t['price_cop'], 0, ',', '.') : '—' ?></td>
        <td class="py-3"><?= (int) $t['sites'] ?></td>
        <td class="py-3"><?= $t['is_active'] ? 'Activa' : '<span class="text-slate-400">Inactiva</span>' ?></td>
        <td class="py-3">
          <div class="flex flex-wrap gap-2 justify-end">
            <a href="<?= e(url('admin/template_edit.php?id=' . (int) $t['id'])) ?>" class="<?= ADMIN_BTN_CLS ?>">Editar</a>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button name="action" value="toggle_premium" class="rounded-lg border border-slate-300 text-sm px-3 py-2 hover:bg-slate-50">
                <?= $t['is_premium'] ? '→ Gratis' : '→ Premium' ?>
              </button>
            </form>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button name="action" value="toggle_active" class="rounded-lg border border-slate-300 text-sm px-3 py-2 hover:bg-slate-50">
                <?= $t['is_active'] ? 'Desactivar' : 'Activar' ?>
              </button>
            </form>
            <?php if ((int) $t['sites'] === 0): ?>
              <form method="post" class="flex items-center gap-1"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <label class="text-xs text-slate-500 flex items-center gap-1">
                  <input type="checkbox" name="delete_file" value="1"> borrar .html
                </label>
                <button name="action" value="delete" class="rounded-lg border border-rose-300 text-rose-700 text-sm px-3 py-2 hover:bg-rose-50">Eliminar</button>
              </form>
            <?php else: ?>
              <span class="text-xs text-slate-400 self-center">en uso</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="<?= ADMIN_CARD_CLS ?> mt-8">
  <h2 class="font-semibold mb-1">Subir nueva plantilla</h2>
  <p class="text-xs text-slate-500 mb-4">
    Archivo <code>.html</code> completo. Variables disponibles: <code><?= e($vars) ?></code>.
    Valores del servidor: <code>{{{nonce}}}</code> (obligatorio en cada <code>&lt;script&gt;</code>) y <code>{{{ad_slot}}}</code>.
    Sin PHP, sin <code>onclick=</code> y solo recursos de <?= e(implode(', ', Admin::ALLOWED_HOSTS)) ?>.
  </p>
  <form method="post" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div>
      <label class="block text-sm font-semibold mb-1" for="name">Nombre</label>
      <input id="name" name="name" class="<?= ADMIN_INPUT_CLS ?>" maxlength="60" required value="<?= e($form['name']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="slug">Identificador <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="slug" name="slug" class="<?= ADMIN_INPUT_CLS ?>" maxlength="40" placeholder="se genera del nombre" value="<?= e($form['slug']) ?>">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-semibold mb-1" for="description">Descripción</label>
      <input id="description" name="description" class="<?= ADMIN_INPUT_CLS ?>" maxlength="200" value="<?= e($form['description']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="price_cop">Precio de referencia (COP)</label>
      <input id="price_cop" name="price_cop" type="number" min="0" max="9999999" step="1" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_cop']) ?>">
    </div>
    <div class="flex items-end">
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="is_premium" value="1" <?= $form['is_premium'] === '1' ? 'checked' : '' ?>> Plantilla premium
      </label>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="html">Archivo HTML</label>
      <input id="html" name="html" type="file" accept=".html,text/html" required class="<?= ADMIN_INPUT_CLS ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="thumbnail">Miniatura <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="thumbnail" name="thumbnail" type="file" accept="image/png,image/jpeg,image/webp" class="<?= ADMIN_INPUT_CLS ?>">
    </div>
    <div class="sm:col-span-2"><button class="<?= ADMIN_BTN_CLS ?>">Crear plantilla</button></div>
  </form>
</section>
<?php admin_page_end();
