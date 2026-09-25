<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Cupones de descuento (1–100 %). Los ajustes de anuncios/precio viven en settings.php. */
$admin = Admin::guard();
$pdo = db();

$errors = [];
$form = ['code' => '', 'discount_percent' => '20', 'expires_at' => '', 'max_uses' => '0', 'scope' => 'all', 'tier_id' => '', 'note' => ''];
$tiers = $pdo->query('SELECT id, name FROM membership_tiers ORDER BY sort_order')->fetchAll();
$viewId = (int) ($_GET['canjes'] ?? 0);

/** Código legible tipo AMOR-7K3Q (sin 0/O/1/I). */
function promo_generate_code(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 4; $i++) {
        $s .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return 'AMOR-' . $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'promo_create') {
        foreach (['code', 'discount_percent', 'expires_at', 'max_uses', 'scope', 'tier_id', 'note'] as $f) {
            $form[$f] = trim((string) ($_POST[$f] ?? $form[$f]));
        }
        $form['code'] = strtoupper($form['code']);
        $generated = $form['code'] === '';
        if ($generated) {
            $form['code'] = promo_generate_code();
        }
        if (!preg_match('/^[A-Z0-9-]{3,32}$/', $form['code'])) {
            $errors[] = 'El código debe tener entre 3 y 32 caracteres [A-Z0-9-].';
        }
        $pct = (int) $form['discount_percent'];
        if (!ctype_digit($form['discount_percent']) || $pct < 1 || $pct > 100) {
            $errors[] = 'El descuento debe estar entre 1 % y 100 %.';
        }
        if ($pct === 100 && empty($_POST['confirm_free'])) {
            $errors[] = 'Marca la casilla: un cupón del 100 % otorga el producto gratis.';
        }
        if ($form['max_uses'] === '' && $pct === 100) {
            $form['max_uses'] = '1';
        }
        if (!ctype_digit($form['max_uses']) || (int) $form['max_uses'] > 100000) {
            $errors[] = 'Los usos máximos deben ser un entero (0 = ilimitado).';
        }
        if (!in_array($form['scope'], ['all', 'tiers', 'templates', 'coins'], true)) {
            $errors[] = 'Alcance inválido.';
        }
        $tierId = null;
        if ($form['scope'] === 'tiers' && $form['tier_id'] !== '') {
            $tierId = (int) $form['tier_id'];
            if (!in_array($tierId, array_map(static fn(array $t): int => (int) $t['id'], $tiers), true)) {
                $errors[] = 'Plan inválido.';
            }
        }
        if (mb_strlen($form['note']) > 120) {
            $errors[] = 'La nota no puede superar 120 caracteres.';
        }
        if ($form['expires_at'] !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $form['expires_at']);
            if (!$d || $d->format('Y-m-d') !== $form['expires_at']) {
                $errors[] = 'Fecha de caducidad inválida.';
            }
        }
        if (!$errors) {
            try {
                $pdo->prepare('INSERT INTO promos (code, discount_percent, expires_at, max_uses, scope, tier_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$form['code'], $pct, $form['expires_at'] !== '' ? $form['expires_at'] : null, (int) $form['max_uses'],
                               $form['scope'], $tierId, $form['note'] !== '' ? $form['note'] : null, (int) $admin['id']]);
                Admin::log($pct === 100 ? 'promo.create_free' : 'promo.create',
                    $form['code'] . " ($pct%, usos {$form['max_uses']}, alcance {$form['scope']}" . ($tierId ? " plan #$tierId" : '') . ')');
                flash("Cupón «{$form['code']}» creado." . ($generated ? ' (código generado)' : ''));
                redirect('admin/promos.php');
            } catch (PDOException $ex) {
                $errors[] = $ex->getCode() === '23000' ? 'Ese código ya existe.' : 'No se pudo crear el cupón.';
                if ($ex->getCode() !== '23000') {
                    error_log('admin promo create: ' . $ex->getMessage());
                }
            }
        }
    }

    if (in_array($action, ['promo_toggle', 'promo_delete'], true)) {
        $pid = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT code, is_active FROM promos WHERE id = ?');
        $st->execute([$pid]);
        if ($promo = $st->fetch()) {
            if ($action === 'promo_toggle') {
                $new = (int) $promo['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE promos SET is_active = ? WHERE id = ?')->execute([$new, $pid]);
                Admin::log('promo.toggle', $promo['code'] . ' -> ' . ($new ? 'activo' : 'inactivo'));
                flash("Cupón «{$promo['code']}» " . ($new ? 'activado.' : 'desactivado.'));
            } else {
                $pdo->prepare('DELETE FROM promos WHERE id = ?')->execute([$pid]);
                Admin::log('promo.delete', (string) $promo['code']);
                flash("Cupón «{$promo['code']}» eliminado.");
            }
        }
        redirect('admin/promos.php');
    }
}

$promos = $pdo->query('SELECT p.id, p.code, p.discount_percent, p.is_active, p.expires_at, p.max_uses, p.uses, p.scope, p.note, t.name AS tier_name
                         FROM promos p LEFT JOIN membership_tiers t ON t.id = p.tier_id ORDER BY p.is_active DESC, p.id DESC LIMIT 200')->fetchAll();
$redemptions = [];
if ($viewId > 0) {
    $st = $pdo->prepare('SELECT u.email, r.created_at FROM promo_redemptions r JOIN users u ON u.id = r.user_id WHERE r.promo_id = ? ORDER BY r.id DESC LIMIT 100');
    $st->execute([$viewId]);
    $redemptions = $st->fetchAll();
}
$free = ($_GET['preset'] ?? '') === 'free';
if ($free && $form['discount_percent'] === '20') {
    $form['discount_percent'] = '100';
    $form['max_uses'] = '1';
    $form['note'] = 'Cortesía';
}

admin_page_start('Cupones', 'promos', 'Descuentos de 1 % a 100 % para membresías y plantillas',
    '<a href="' . e(url('admin/promos.php?preset=free')) . '#nuevo" class="' . ADMIN_BTN_CLS . '">Crear cupón de cortesía 100 %</a>');
admin_errors($errors);
?>
<section class="<?= ADMIN_CARD_CLS ?> overflow-x-auto">
  <?php if (!$promos): echo admin_empty('Todavía no hay cupones', 'Crea el primero abajo.'); else:
      echo admin_table_open(['Código', 'Descuento', 'Alcance', 'Caduca', 'Usos', 'Estado', 'Acciones' => 'right']);
      foreach ($promos as $p):
          $expired = $p['expires_at'] !== null && $p['expires_at'] < gmdate('Y-m-d');
          $spent   = (int) $p['max_uses'] > 0 && (int) $p['uses'] >= (int) $p['max_uses'];
          [$lbl, $tone] = !$p['is_active'] ? ['Desactivado', 'slate'] : ($expired ? ['Vencido', 'rose'] : ($spent ? ['Agotado', 'amber'] : ['Activo', 'green']));
          $scope = match ($p['scope']) { 'tiers' => $p['tier_name'] ? 'Plan ' . $p['tier_name'] : 'Todos los planes', 'templates' => 'Plantillas', 'coins' => 'Recargas de monedas', default => 'Todo' }; ?>
    <tr>
      <td class="px-4 py-3"><span class="font-mono font-semibold"><?= e($p['code']) ?></span>
        <?php if ($p['note']): ?><p class="text-xs text-slate-500"><?= e($p['note']) ?></p><?php endif; ?></td>
      <td class="px-4 py-3"><?= (int) $p['discount_percent'] === 100 ? admin_badge('100 % gratis', 'violet') : (int) $p['discount_percent'] . ' %' ?></td>
      <td class="px-4 py-3"><?= e($scope) ?></td>
      <td class="px-4 py-3"><?= e($p['expires_at'] ?? 'sin caducidad') ?></td>
      <td class="px-4 py-3 tabular"><?= (int) $p['uses'] ?><?= (int) $p['max_uses'] > 0 ? ' / ' . (int) $p['max_uses'] : ' / ∞' ?></td>
      <td class="px-4 py-3"><?= admin_badge($lbl, $tone) ?></td>
      <td class="px-4 py-3"><div class="flex gap-2 justify-end">
        <a href="<?= e(url('admin/promos.php?canjes=' . (int) $p['id'])) ?>#canjes" class="<?= ADMIN_BTN_GHOST_CLS ?>">Canjes</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button name="action" value="promo_toggle" class="<?= ADMIN_BTN_GHOST_CLS ?>"><?= $p['is_active'] ? 'Desactivar' : 'Activar' ?></button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button name="action" value="promo_delete" class="<?= ADMIN_BTN_DANGER_CLS ?>">Eliminar</button></form>
      </div></td>
    </tr>
  <?php endforeach; echo admin_table_close(); endif; ?>
</section>

<?php if ($viewId > 0): ?>
<section id="canjes" class="<?= ADMIN_CARD_CLS ?> mt-6">
  <h2 class="font-semibold mb-3">Canjes del cupón #<?= $viewId ?></h2>
  <?php if (!$redemptions): ?><p class="text-sm text-slate-500">Nadie lo ha canjeado todavía.</p>
  <?php else: ?><ul class="text-sm divide-y divide-slate-100">
    <?php foreach ($redemptions as $r): ?><li class="py-2 flex justify-between"><span><?= e($r['email']) ?></span><span class="text-slate-500"><?= e(admin_date($r['created_at'])) ?></span></li><?php endforeach; ?>
  </ul><?php endif; ?>
</section>
<?php endif; ?>

<section id="nuevo" class="<?= ADMIN_CARD_CLS ?> mt-6">
  <h2 class="font-semibold mb-4">Nuevo cupón</h2>
  <form method="post" class="grid gap-4 sm:grid-cols-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="promo_create">
    <div><label class="block text-sm font-semibold mb-1" for="code">Código <span class="font-normal text-slate-500">(vacío = generar)</span></label>
      <input id="code" name="code" class="<?= ADMIN_INPUT_CLS ?> font-mono uppercase" maxlength="32" pattern="[A-Za-z0-9-]{3,32}" placeholder="AMOR-7K3Q" value="<?= e($form['code']) ?>"></div>
    <div><label class="block text-sm font-semibold mb-1" for="discount_percent">Descuento (%)</label>
      <input id="discount_percent" name="discount_percent" type="number" min="1" max="100" required class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['discount_percent']) ?>"></div>
    <div><label class="block text-sm font-semibold mb-1" for="max_uses">Usos máx. <span class="font-normal text-slate-500">(0 = ∞; 100 %: 1 por defecto)</span></label>
      <input id="max_uses" name="max_uses" type="number" min="0" max="100000" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['max_uses']) ?>"></div>
    <div><label class="block text-sm font-semibold mb-1" for="expires_at">Caduca <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="expires_at" name="expires_at" type="date" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['expires_at']) ?>"></div>
    <div><label class="block text-sm font-semibold mb-1" for="scope">Alcance</label>
      <select id="scope" name="scope" class="<?= ADMIN_INPUT_CLS ?>">
        <option value="all"<?= $form['scope'] === 'all' ? ' selected' : '' ?>>Todo (incluye monedas)</option>
        <option value="tiers"<?= $form['scope'] === 'tiers' ? ' selected' : '' ?>>Solo membresías</option>
        <option value="templates"<?= $form['scope'] === 'templates' ? ' selected' : '' ?>>Solo plantillas</option>
        <option value="coins"<?= $form['scope'] === 'coins' ? ' selected' : '' ?>>Solo recargas de monedas</option></select></div>
    <div><label class="block text-sm font-semibold mb-1" for="tier_id">Plan concreto <span class="font-normal text-slate-500">(con «Solo membresías»)</span></label>
      <select id="tier_id" name="tier_id" class="<?= ADMIN_INPUT_CLS ?>"><option value="">Cualquiera</option>
        <?php foreach ($tiers as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $form['tier_id'] === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="sm:col-span-3"><label class="block text-sm font-semibold mb-1" for="note">Nota <span class="font-normal text-slate-500">(opcional)</span></label>
      <input id="note" name="note" maxlength="120" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($form['note']) ?>"></div>
    <div class="sm:col-span-3 rounded-lg bg-amber-50 border border-amber-200 p-3">
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm_free" value="1" class="mt-1"<?= $free ? ' checked' : '' ?>>
        Entiendo que este cupón otorga el producto gratis (solo necesario con descuento del 100 %).</label></div>
    <div class="sm:col-span-3"><button class="<?= ADMIN_BTN_CLS ?>">Crear cupón</button></div>
  </form>
</section>
<?php admin_page_end();
