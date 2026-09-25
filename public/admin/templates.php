<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Gestor de plantillas: listado, alta (subida de .html o de una carpeta PHP en .zip) y acciones rápidas. */
Admin::guard();
$pdo = db();

$errors = [];
$form   = ['name' => '', 'slug' => '', 'description' => '', 'price_usd' => '0.00', 'price_coins' => '0', 'is_premium' => '0', 'kind' => 'html', 'category' => 'romantico'];

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

                if (($tpl['kind'] ?? 'html') === 'php' && !empty($_POST['delete_file'])) {
                    PhpTemplate::remove((string) $tpl['slug']);
                }
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
        $form['price_usd']   = (string) ($_POST['price_usd'] ?? '0');
        $form['price_coins'] = (string) ($_POST['price_coins'] ?? '0');
        $form['is_premium']  = !empty($_POST['is_premium']) ? '1' : '0';
        $form['kind']        = ($_POST['kind'] ?? '') === 'php' ? 'php' : 'html';
        $form['category']    = array_key_exists((string) ($_POST['category'] ?? ''), Template::CATEGORIES) ? (string) $_POST['category'] : 'romantico';
        $isPhp = $form['kind'] === 'php';
        if ($isPhp) {
            // Las carpetas PHP admiten guion bajo (p. ej. flores_amarillas); se toma el texto tal cual, en minúsculas.
            $form['slug'] = strtolower(trim((string) ($_POST['slug'] ?? ''))) ?: str_replace('-', '_', Admin::slugify($form['name']));
        }

        if ($form['name'] === '' || mb_strlen($form['name']) > 60) {
            $errors[] = 'El nombre es obligatorio (máximo 60 caracteres).';
        }
        if (!preg_match($isPhp ? PhpTemplate::SLUG_RE : '/^[a-z0-9-]{3,40}$/', $form['slug'])) {
            $errors[] = $isPhp ? 'El identificador debe tener entre 3 y 40 caracteres [a-z0-9_-].' : 'El identificador debe tener entre 3 y 40 caracteres [a-z0-9-].';
        }
        if (mb_strlen($form['description']) > 200) {
            $errors[] = 'La descripción no puede superar 200 caracteres.';
        }
        $coins = filter_var($form['price_coins'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($coins === false) {
            $errors[] = 'El costo en monedas debe ser un entero entre 0 y 100000.';
        }
        $price = Admin::parsePriceUsd($form['price_usd']);
        if ($price === null) {
            $errors[] = 'El precio debe ser un monto en USD, hasta 999.99 con 2 decimales (0 = sin compra individual).';
        }

        $html = '';
        $zipTmp = '';
        if ($isPhp) {
            $file = $_FILES['bundle'] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if ($err = Admin::uploadError($file)) {
                $errors[] = $err;
            } elseif (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
                $errors[] = 'La plantilla PHP debe subirse como archivo .zip.';
            } else {
                $zipTmp = (string) $file['tmp_name'];
            }
        } else {
            $file = $_FILES['html'] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if ($err = Admin::uploadError($file)) {
                $errors[] = $err;
            } else {
                $html = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, Admin::MAX_TEMPLATE_BYTES + 1);
                $errors = array_merge($errors, Admin::validateTemplateHtml($html));
            }
        }

        if (!$errors) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM templates WHERE slug = ?');
            $st->execute([$form['slug']]);
            if ((int) $st->fetchColumn() > 0) {
                $errors[] = 'Ya existe una plantilla con ese identificador.';
            } elseif ($isPhp ? is_dir(PhpTemplate::baseDir() . '/' . $form['slug']) : is_file(Admin::templateDir() . '/' . $form['slug'] . '.html')) {
                $errors[] = 'Ya hay una plantilla con ese nombre en /templates. Usa otro identificador.';
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
                if ($isPhp) {
                    // Análisis estático + extracción segura; si algo falla no se crea nada.
                    if ($installErrors = PhpTemplate::install($form['slug'], $zipTmp)) {
                        Admin::deleteThumbnail($thumb);
                        $errors = array_merge($errors, $installErrors);
                        throw new DomainException('bundle');
                    }
                    $fileCol = $form['slug'] . '/index.php';
                } else {
                    Admin::writeTemplateFile($form['slug'] . '.html', $html);
                    $fileCol = $form['slug'] . '.html';
                }
                $pdo->prepare('INSERT INTO templates (slug, name, kind, category, file, description, price_usd, price_coins, thumbnail, is_premium, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
                    ->execute([
                        $form['slug'], $form['name'], $form['kind'], $form['category'], $fileCol,
                        $form['description'], (string) $price, (int) $coins, $thumb, (int) $form['is_premium'],
                    ]);
                Admin::log('template.create', $form['slug']);
                flash("Plantilla «{$form['name']}» creada.");
                redirect('admin/templates.php');
            } catch (DomainException) {
                // errores ya anotados en $errors por la instalación del bundle
            } catch (Throwable $ex) {
                error_log('admin template create: ' . $ex->getMessage());
                Admin::deleteThumbnail($thumb);
                if ($isPhp) {
                    PhpTemplate::remove($form['slug']);
                }
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

admin_page_start('Plantillas', 'templates', 'Catálogo de plantillas: precios, estado y uso.', '<a href="#subir" class="' . ADMIN_BTN_CLS . '">Subir plantilla</a>');
admin_errors($errors);
?>
<section class="<?= ADMIN_CARD_CLS ?> overflow-x-auto !p-0">
  <table class="w-full text-sm">
    <thead class="bg-slate-50"><tr class="text-left text-xs uppercase tracking-wide text-slate-500">
      <th scope="col" class="px-4 py-2.5">Plantilla</th><th scope="col" class="px-4 py-2.5">Tipo</th><th scope="col" class="px-4 py-2.5">Precio</th>
      <th scope="col" class="px-4 py-2.5">En uso</th><th scope="col" class="px-4 py-2.5">Estado</th><th scope="col" class="px-4 py-2.5 text-right">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr class="border-t border-slate-100 align-top hover:bg-slate-50/70">
        <td class="px-4 py-3">
          <div class="flex gap-3">
            <?php if ($t['thumbnail']): ?>
              <img src="<?= e(url('assets/thumbs/' . $t['thumbnail'])) ?>" alt="" width="48" height="48" class="w-12 h-12 rounded object-cover border border-slate-200">
            <?php endif; ?>
            <div>
              <p class="font-semibold"><?= e($t['name']) ?></p>
              <p class="text-xs text-slate-500"><?= e($t['slug']) ?> · <?= e($t['file']) ?> · <?= $t['kind'] === 'php' ? '<span class="text-indigo-600 font-semibold">PHP</span>' : 'HTML' ?> · <?= e(Template::CATEGORIES[$t['category']] ?? $t['category']) ?></p>
              <?php if ($t['description']): ?><p class="text-xs text-slate-500 mt-1 max-w-sm"><?= e($t['description']) ?></p><?php endif; ?>
            </div>
          </div>
        </td>
        <td class="px-4 py-3 <?= $t['is_premium'] ? 'text-amber-600' : 'text-emerald-600' ?> font-semibold"><?= $t['is_premium'] ? 'Premium' : 'Gratis' ?></td>
        <td class="px-4 py-3"><?= Access::priceInCents($t) > 0 ? '$' . e(wompi_format_usd(Access::priceInCents($t))) . ' USD' : '—' ?></td>
        <td class="px-4 py-3"><?= (int) $t['sites'] ?></td>
        <td class="px-4 py-3"><?= $t['is_active'] ? 'Activa' : '<span class="text-slate-400">Inactiva</span>' ?></td>
        <td class="px-4 py-3">
          <div class="flex flex-wrap gap-2 justify-end">
            <a href="<?= e(url('admin/template_edit.php?id=' . (int) $t['id'])) ?>" class="<?= ADMIN_BTN_CLS ?>">Editar</a>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button name="action" value="toggle_premium" class="rounded-lg border border-slate-300 text-sm px-3 py-2 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                <?= $t['is_premium'] ? '→ Gratis' : '→ Premium' ?>
              </button>
            </form>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button name="action" value="toggle_active" class="rounded-lg border border-slate-300 text-sm px-3 py-2 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                <?= $t['is_active'] ? 'Desactivar' : 'Activar' ?>
              </button>
            </form>
            <?php if ((int) $t['sites'] === 0): ?>
              <form method="post" class="flex items-center gap-1"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <label class="text-xs text-slate-500 flex items-center gap-1">
                  <input type="checkbox" name="delete_file" value="1"> borrar archivos
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

<section id="subir" class="<?= ADMIN_CARD_CLS ?> mt-8">
  <h2 class="font-semibold mb-1">Subir nueva plantilla</h2>
  <p class="text-xs text-slate-500 mb-4">
    <strong>HTML:</strong> archivo <code>.html</code> completo. Variables disponibles: <code><?= e($vars) ?></code>.
    Valores del servidor: <code>{{{nonce}}}</code> (obligatorio en cada <code>&lt;script&gt;</code>) y <code>{{{ad_slot}}}</code>.
    Sin PHP, sin <code>onclick=</code> y solo recursos de <?= e(implode(', ', Admin::ALLOWED_HOSTS)) ?>.
    <br><strong>PHP:</strong> un <code>.zip</code> con <code>index.php</code> en la raíz (o dentro de una única carpeta), más css/js/imágenes/fuentes.
    Recibe <code>$t</code> (campos ya escapados: <code>$t['your_name']</code>…), <code>$nonce</code>, <code>$ad_slot</code> y <code>$assets</code> (URL base: <code>&lt;?= $assets ?&gt;css/app.css</code>).
    Se rechaza todo lo que ejecute comandos, toque ficheros/red/BD, use superglobales o llamadas dinámicas; <code>include</code> solo con <code>__DIR__ . '/ruta.php'</code>.
    Revisa siempre el código antes de subirlo: el análisis es una barrera, no un sandbox.
  </p>
  <form method="post" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div>
      <label class="block text-sm font-semibold mb-1" for="kind">Tipo</label>
      <select id="kind" name="kind" class="<?= ADMIN_INPUT_CLS ?>">
        <option value="html" <?= $form['kind'] === 'html' ? 'selected' : '' ?>>HTML con {{campos}}</option>
        <option value="php" <?= $form['kind'] === 'php' ? 'selected' : '' ?>>Carpeta PHP (.zip)</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="category">Categoría</label>
      <select id="category" name="category" class="<?= ADMIN_INPUT_CLS ?>">
        <?php foreach (Template::CATEGORIES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $form['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
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
      <label class="block text-sm font-semibold mb-1" for="price_coins">Costo en monedas</label>
      <input id="price_coins" name="price_coins" type="number" min="0" max="100000" step="1" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_coins']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="price_usd">Precio individual (USD)</label>
      <input id="price_usd" name="price_usd" type="number" min="0" max="999.99" step="0.01" inputmode="decimal" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['price_usd']) ?>">
      <p class="text-xs text-slate-500 mt-1">0 = solo con membresía. Mayor que 0 = también se puede comprar suelta.</p>
    </div>
    <div class="flex items-end">
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="is_premium" value="1" <?= $form['is_premium'] === '1' ? 'checked' : '' ?>> Plantilla premium
      </label>
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="html">Archivo HTML <span class="font-normal text-slate-500">(tipo HTML)</span></label>
      <input id="html" name="html" type="file" accept=".html,text/html" class="<?= ADMIN_INPUT_CLS ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="bundle">Carpeta PHP en .zip <span class="font-normal text-slate-500">(tipo PHP)</span></label>
      <input id="bundle" name="bundle" type="file" accept=".zip,application/zip" class="<?= ADMIN_INPUT_CLS ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="thumbnail">Miniatura <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="thumbnail" name="thumbnail" type="file" accept="image/png,image/jpeg,image/webp" class="<?= ADMIN_INPUT_CLS ?>">
    </div>
    <div class="sm:col-span-2"><button class="<?= ADMIN_BTN_CLS ?>">Crear plantilla</button></div>
  </form>
</section>
<?php admin_page_end();
