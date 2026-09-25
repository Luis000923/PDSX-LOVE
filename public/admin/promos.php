<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Anuncios (banner global + slot publicitario), precio Premium y códigos de promoción. */
Admin::guard();
$pdo = db();

$errors = [];
$promoForm = ['code' => '', 'discount_percent' => '20', 'expires_at' => '', 'max_uses' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $action = (string) ($_POST['action'] ?? '');

    // ---- Ajustes de anuncios y precio ------------------------------------
    if ($action === 'settings') {
        $adsHtml      = trim((string) ($_POST['ads_html'] ?? ''));
        $announcement = trim((string) ($_POST['announcement_text'] ?? ''));
        $price        = trim((string) ($_POST['premium_price_cop'] ?? ''));

        if ($adsHtml !== '') {
            $errors = array_merge($errors, Admin::validateAdHtml($adsHtml));
        }
        if (mb_strlen($announcement) > 200) {
            $errors[] = 'El aviso global no puede superar 200 caracteres.';
        }
        if ($price !== '' && (!ctype_digit($price) || (int) $price < 1500 || (int) $price > 9999999)) {
            $errors[] = 'El precio Premium debe ser un entero entre 1500 y 9999999 pesos (mínimo de Wompi).';
        }
        if (!empty($_POST['announcement_enabled']) && $announcement === '') {
            $errors[] = 'No puedes activar el aviso global sin texto.';
        }

        if (!$errors) {
            Admin::setSetting('ads_enabled', !empty($_POST['ads_enabled']) ? '1' : '0');
            Admin::setSetting('ads_html', $adsHtml);
            Admin::setSetting('announcement_enabled', !empty($_POST['announcement_enabled']) ? '1' : '0');
            Admin::setSetting('announcement_text', $announcement);
            Admin::setSetting('premium_price_cop', $price);
            Admin::log('settings.update', 'anuncios y precio');
            flash('Ajustes guardados.');
            redirect('admin/promos.php');
        }
    }

    // ---- Alta de código de promoción -------------------------------------
    if ($action === 'promo_create') {
        $promoForm['code']             = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $promoForm['discount_percent'] = (string) ($_POST['discount_percent'] ?? '');
        $promoForm['expires_at']       = trim((string) ($_POST['expires_at'] ?? ''));
        $promoForm['max_uses']         = (string) ($_POST['max_uses'] ?? '0');

        if (!preg_match('/^[A-Z0-9-]{3,32}$/', $promoForm['code'])) {
            $errors[] = 'El código debe tener entre 3 y 32 caracteres [A-Z0-9-].';
        }
        $pct = (int) $promoForm['discount_percent'];
        if (!ctype_digit($promoForm['discount_percent']) || $pct < 1 || $pct > 90) {
            $errors[] = 'El descuento debe estar entre 1 % y 90 %.';
        }
        if (!ctype_digit($promoForm['max_uses']) || (int) $promoForm['max_uses'] > 100000) {
            $errors[] = 'Los usos máximos deben ser un entero (0 = ilimitado).';
        }
        if ($promoForm['expires_at'] !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $promoForm['expires_at']);
            if (!$d || $d->format('Y-m-d') !== $promoForm['expires_at']) {
                $errors[] = 'Fecha de caducidad inválida.';
            }
        }

        if (!$errors) {
            try {
                $pdo->prepare('INSERT INTO promos (code, discount_percent, expires_at, max_uses) VALUES (?, ?, ?, ?)')
                    ->execute([
                        $promoForm['code'], $pct,
                        $promoForm['expires_at'] !== '' ? $promoForm['expires_at'] : null,
                        (int) $promoForm['max_uses'],
                    ]);
                Admin::log('promo.create', $promoForm['code'] . " ($pct%)");
                flash("Código «{$promoForm['code']}» creado.");
                redirect('admin/promos.php');
            } catch (PDOException $ex) {
                $errors[] = $ex->getCode() === '23000' ? 'Ese código ya existe.' : 'No se pudo crear el código.';
                if ($ex->getCode() !== '23000') {
                    error_log('admin promo create: ' . $ex->getMessage());
                }
            }
        }
    }

    // ---- Activar/desactivar o borrar un código ---------------------------
    if (in_array($action, ['promo_toggle', 'promo_delete'], true)) {
        $pid = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT code, is_active FROM promos WHERE id = ?');
        $st->execute([$pid]);
        $promo = $st->fetch();

        if ($promo) {
            if ($action === 'promo_toggle') {
                $new = (int) $promo['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE promos SET is_active = ? WHERE id = ?')->execute([$new, $pid]);
                Admin::log('promo.toggle', $promo['code'] . ' -> ' . ($new ? 'activo' : 'inactivo'));
                flash("Código «{$promo['code']}» " . ($new ? 'activado.' : 'desactivado.'));
            } else {
                $pdo->prepare('DELETE FROM promos WHERE id = ?')->execute([$pid]);
                Admin::log('promo.delete', (string) $promo['code']);
                flash("Código «{$promo['code']}» eliminado.");
            }
        }
        redirect('admin/promos.php');
    }
}

$settings = Admin::settings();
$promos   = $pdo->query('SELECT * FROM promos ORDER BY is_active DESC, id DESC')->fetchAll();
$envPrice = (int) env('PREMIUM_PRICE_COP', '19900');

admin_page_start('Promociones', 'promos');
admin_errors($errors);
?>
<h1 class="text-2xl font-bold mb-6">Promociones y anuncios</h1>

<section class="<?= ADMIN_CARD_CLS ?>">
  <h2 class="font-semibold mb-4">Anuncios y precio</h2>
  <form method="post" class="space-y-5">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">

    <div>
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="ads_enabled" value="1" <?= $settings['ads_enabled'] === '1' ? 'checked' : '' ?>>
        Mostrar publicidad en las páginas de cuentas gratuitas
      </label>
      <label class="block text-sm font-semibold mt-3 mb-1" for="ads_html">HTML del banner</label>
      <textarea id="ads_html" name="ads_html" rows="4" maxlength="4096" class="<?= ADMIN_INPUT_CLS ?> font-mono text-xs"
                placeholder="&lt;a href=&quot;https://…&quot;&gt;&lt;img src=&quot;/love/assets/thumbs/banner.png&quot; alt=&quot;&quot;&gt;&lt;/a&gt;"><?= e($settings['ads_html']) ?></textarea>
      <p class="text-xs text-slate-500 mt-1">
        Sin <code>&lt;script&gt;</code>, <code>&lt;iframe&gt;</code> ni <code>onclick=</code>; las imágenes deben servirse desde este dominio (CSP).
        Si lo dejas vacío se muestra el marcador «Publicidad».
      </p>
    </div>

    <div>
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="announcement_enabled" value="1" <?= $settings['announcement_enabled'] === '1' ? 'checked' : '' ?>>
        Mostrar aviso global en la app (cabecera)
      </label>
      <input name="announcement_text" maxlength="200" class="<?= ADMIN_INPUT_CLS ?> mt-2"
             placeholder="Ej.: 30 % de descuento con el código AMOR30 hasta el domingo"
             value="<?= e($settings['announcement_text']) ?>">
      <p class="text-xs text-slate-500 mt-1">Texto plano: se escapa antes de mostrarse.</p>
    </div>

    <div class="max-w-xs">
      <label class="block text-sm font-semibold mb-1" for="premium_price_cop">Precio Premium (COP)</label>
      <input id="premium_price_cop" name="premium_price_cop" type="number" min="1500" max="9999999" step="1"
             class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($settings['premium_price_cop']) ?>"
             placeholder="<?= e((string) $envPrice) ?>">
      <p class="text-xs text-slate-500 mt-1">Vacío = usar <code>PREMIUM_PRICE_COP</code> del .env ($<?= e(number_format($envPrice, 0, ',', '.')) ?>).</p>
    </div>

    <button class="<?= ADMIN_BTN_CLS ?>">Guardar ajustes</button>
  </form>
</section>

<section class="<?= ADMIN_CARD_CLS ?> mt-8 overflow-x-auto">
  <h2 class="font-semibold mb-4">Códigos de descuento</h2>
  <?php if (!$promos): ?>
    <p class="text-sm text-slate-500 mb-4">Todavía no hay códigos.</p>
  <?php else: ?>
    <table class="w-full text-sm mb-4">
      <thead><tr class="text-left text-xs uppercase text-slate-500">
        <th class="pb-2">Código</th><th class="pb-2">Descuento</th><th class="pb-2">Caduca</th>
        <th class="pb-2">Usos</th><th class="pb-2">Estado</th><th class="pb-2 text-right">Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($promos as $p):
          $expired = $p['expires_at'] !== null && $p['expires_at'] < date('Y-m-d');
          $spent   = (int) $p['max_uses'] > 0 && (int) $p['uses'] >= (int) $p['max_uses']; ?>
        <tr class="border-t border-slate-100">
          <td class="py-2 font-mono font-semibold"><?= e($p['code']) ?></td>
          <td class="py-2"><?= (int) $p['discount_percent'] ?> %</td>
          <td class="py-2 <?= $expired ? 'text-rose-600' : 'text-slate-500' ?>"><?= e($p['expires_at'] ?? 'sin caducidad') ?></td>
          <td class="py-2 <?= $spent ? 'text-rose-600' : '' ?>">
            <?= (int) $p['uses'] ?><?= (int) $p['max_uses'] > 0 ? ' / ' . (int) $p['max_uses'] : '' ?>
          </td>
          <td class="py-2 font-semibold <?= $p['is_active'] && !$expired && !$spent ? 'text-emerald-600' : 'text-slate-400' ?>">
            <?= $p['is_active'] ? ($expired ? 'Caducado' : ($spent ? 'Agotado' : 'Activo')) : 'Inactivo' ?>
          </td>
          <td class="py-2">
            <div class="flex gap-2 justify-end">
              <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button name="action" value="promo_toggle" class="rounded-lg border border-slate-300 text-sm px-3 py-1.5 hover:bg-slate-50">
                  <?= $p['is_active'] ? 'Desactivar' : 'Activar' ?>
                </button>
              </form>
              <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button name="action" value="promo_delete" class="rounded-lg border border-rose-300 text-rose-700 text-sm px-3 py-1.5 hover:bg-rose-50">Eliminar</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" class="grid gap-4 sm:grid-cols-4 border-t border-slate-100 pt-4">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="promo_create">
    <div>
      <label class="block text-sm font-semibold mb-1" for="code">Código</label>
      <input id="code" name="code" class="<?= ADMIN_INPUT_CLS ?> font-mono uppercase" maxlength="32" required
             pattern="[A-Za-z0-9-]{3,32}" value="<?= e($promoForm['code']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="discount_percent">Descuento (%)</label>
      <input id="discount_percent" name="discount_percent" type="number" min="1" max="90" step="1" required
             class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($promoForm['discount_percent']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="expires_at">Caduca <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="expires_at" name="expires_at" type="date" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($promoForm['expires_at']) ?>">
    </div>
    <div>
      <label class="block text-sm font-semibold mb-1" for="max_uses">Usos máx. <span class="font-normal text-slate-500">(0 = ∞)</span></label>
      <input id="max_uses" name="max_uses" type="number" min="0" max="100000" step="1" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($promoForm['max_uses']) ?>">
    </div>
    <div class="sm:col-span-4"><button class="<?= ADMIN_BTN_CLS ?>">Crear código</button></div>
  </form>
</section>
<?php admin_page_end();
