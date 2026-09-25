<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Ficha de un usuario: datos, historiales y acciones de administración. */
$admin = Admin::guard();
$adminId = (int) $admin['id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $id > 0 ? AdminUsers::find($id) : null;
if ($user === null) {
    render_error(404);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $action = (string) ($_POST['action'] ?? '');
    $reason = (string) ($_POST['reason'] ?? '');
    try {
        switch ($action) {
            case 'coins':
                $sign = ($_POST['sign'] ?? '+') === '-' ? -1 : 1;
                $new = AdminUsers::adjustCoins($id, $sign * (int) ($_POST['amount'] ?? 0), $reason, $adminId);
                flash("Saldo actualizado: $new monedas.");
                break;
            case 'grant':
                $until = AdminUsers::grantPlan($id, (int) ($_POST['tier_id'] ?? 0), (int) ($_POST['months'] ?? 0), $reason, $adminId, !empty($_POST['force']), !empty($_POST['bonus']));
                flash('Plan asignado hasta ' . admin_date($until) . '.');
                break;
            case 'unplan':
                AdminUsers::removePlan($id, $reason, $adminId);
                flash('Plan quitado: la cuenta es gratuita.');
                break;
            case 'suspend':
            case 'unsuspend':
                AdminUsers::setSuspended($id, $action === 'suspend', $reason, $adminId);
                flash($action === 'suspend' ? 'Cuenta suspendida.' : 'Cuenta reactivada.');
                break;
            case 'delete':
                AdminUsers::deleteAccount($id, (string) ($_POST['confirm_email'] ?? ''), $reason, $adminId);
                flash('Cuenta eliminada.');
                redirect('admin/users.php');
            default:
                throw new InvalidArgumentException('Acción desconocida.');
        }
        redirect('admin/user.php?id=' . $id);
    } catch (InvalidArgumentException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$user = AdminUsers::find($id) ?: $user;
$tier = Access::userTier($user);
$usage = Access::siteUsage($user);
$sites = AdminUsers::sitesOf($id);
$payments = AdminUsers::paymentsOf($id);
$coinTx = Coins::history($id, 15);
$audit = AdminUsers::auditOf($id);
$tiers = Access::tiers();
$suspended = (int) $user['is_suspended'] === 1;
$isAdminUser = (int) $user['is_admin'] === 1;
$isSelf = $id === (int) $admin['id'];

$field = static fn(string $label, string $body): string => '<label class="block text-xs font-semibold text-slate-600 mb-1">' . e($label) . '</label>' . $body;
$reasonInput = '<input class="' . ADMIN_INPUT_CLS . '" name="reason" maxlength="200" required placeholder="Nota / motivo (obligatorio)">';
$hidden = csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';

admin_page_start($user['email'], 'users', 'Usuario #' . $id . ' · alta ' . admin_date($user['created_at']), '<a class="' . ADMIN_BTN_GHOST_CLS . '" href="' . e(url('admin/users.php')) . '">Volver</a>');
admin_errors($errors);
?>
<div class="grid lg:grid-cols-5 gap-6">
  <div class="lg:col-span-2 space-y-6">
    <section class="<?= ADMIN_CARD_CLS ?>">
      <div class="flex flex-wrap gap-2 mb-4">
        <?= $tier ? admin_badge((string) $tier['name'], 'violet') : admin_badge('Gratuito', 'slate') ?>
        <?= $suspended ? admin_badge('Suspendida', 'rose') : admin_badge('Activa', 'green') ?>
        <?= $isAdminUser ? admin_badge('Admin', 'blue') : '' ?>
      </div>
      <dl class="text-sm space-y-2">
        <div class="flex justify-between gap-3"><dt class="text-slate-500">Plan</dt><dd class="font-semibold"><?= e($tier ? (string) $tier['name'] : (Access::wasMember($user) ? 'Vencido' : 'Gratuito')) ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-500">Vencimiento</dt><dd><?= $user['membership_tier_id'] === null ? '—' : ($user['membership_expires_at'] === null ? 'No vence' : e(admin_date((string) $user['membership_expires_at']))) ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-500">Monedas</dt><dd class="font-semibold tabular"><?= (int) $user['coins'] ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= $usage['mode'] === 'month' ? 'Cupo gratuito del mes' : 'Páginas activas / cupo' ?></dt><dd class="tabular"><?= $usage['used'] ?> / <?= $usage['allowed'] ?></dd></div>
        <?php if ($suspended): ?>
        <div class="flex justify-between gap-3"><dt class="text-slate-500">Suspendida</dt><dd><?= e(admin_date((string) $user['suspended_at'])) ?></dd></div>
        <div><dt class="text-slate-500">Motivo</dt><dd><?= e((string) $user['suspended_reason']) ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="<?= ADMIN_CARD_CLS ?>">
      <h2 class="font-semibold mb-3">Acciones</h2>
      <div class="space-y-3">
        <details class="rounded-xl border border-slate-200 p-3">
          <summary class="cursor-pointer text-sm font-semibold list-none">Ajustar monedas</summary>
          <form method="post" class="mt-3 space-y-3"><?= $hidden ?><input type="hidden" name="action" value="coins">
            <div class="flex gap-2">
              <select name="sign" class="<?= ADMIN_INPUT_CLS ?> !w-24"><option value="+">Sumar</option><option value="-">Restar</option></select>
              <input class="<?= ADMIN_INPUT_CLS ?>" type="number" name="amount" min="1" max="<?= AdminUsers::MAX_COINS_DELTA ?>" required placeholder="Cantidad">
            </div>
            <?= $reasonInput ?>
            <button class="<?= ADMIN_BTN_CLS ?>">Confirmar ajuste</button>
          </form>
        </details>
        <details class="rounded-xl border border-slate-200 p-3">
          <summary class="cursor-pointer text-sm font-semibold list-none">Asignar / extender plan</summary>
          <form method="post" class="mt-3 space-y-3"><?= $hidden ?><input type="hidden" name="action" value="grant">
            <div class="flex gap-2">
              <select name="tier_id" class="<?= ADMIN_INPUT_CLS ?>"><?php foreach ($tiers as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e((string) $t['name']) ?></option><?php endforeach; ?></select>
              <input class="<?= ADMIN_INPUT_CLS ?> !w-24" type="number" name="months" min="1" max="<?= AdminUsers::MAX_MONTHS ?>" value="1" required aria-label="Meses">
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="bonus" value="1"> Abonar bono de monedas del plan</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="force" value="1"> Forzar (reemplazar un plan superior vigente)</label>
            <?= $reasonInput ?>
            <button class="<?= ADMIN_BTN_CLS ?>">Confirmar plan</button>
          </form>
        </details>
        <?php if ($user['membership_tier_id'] !== null): ?>
        <details class="rounded-xl border border-slate-200 p-3">
          <summary class="cursor-pointer text-sm font-semibold list-none">Quitar plan</summary>
          <form method="post" class="mt-3 space-y-3"><?= $hidden ?><input type="hidden" name="action" value="unplan">
            <?= $reasonInput ?>
            <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Confirmar: volver a gratuito</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
    </section>

    <section class="rounded-2xl border border-rose-300 bg-rose-50/60 p-5">
      <h2 class="font-semibold text-rose-900 mb-1">Zona de riesgo</h2>
      <?php if ($isAdminUser || $isSelf): ?>
        <p class="text-sm text-rose-800">Los administradores y tu propia cuenta no se pueden suspender ni eliminar desde el panel.</p>
      <?php else: ?>
      <div class="space-y-3 mt-3">
        <details class="rounded-xl border border-rose-200 bg-white p-3">
          <summary class="cursor-pointer text-sm font-semibold list-none"><?= $suspended ? 'Reactivar cuenta' : 'Suspender cuenta' ?></summary>
          <form method="post" class="mt-3 space-y-3"><?= $hidden ?><input type="hidden" name="action" value="<?= $suspended ? 'unsuspend' : 'suspend' ?>">
            <p class="text-xs text-slate-600"><?= $suspended ? 'Podrá iniciar sesión y sus páginas volverán a mostrarse.' : 'No podrá iniciar sesión, se cierran sus sesiones y sus páginas públicas dejan de mostrarse.' ?></p>
            <?= $reasonInput ?>
            <button class="<?= ADMIN_BTN_DANGER_CLS ?>"><?= $suspended ? 'Confirmar reactivación' : 'Confirmar suspensión' ?></button>
          </form>
        </details>
        <details class="rounded-xl border border-rose-200 bg-white p-3">
          <summary class="cursor-pointer text-sm font-semibold text-rose-700 list-none">Eliminar cuenta y datos</summary>
          <form method="post" class="mt-3 space-y-3"><?= $hidden ?><input type="hidden" name="action" value="delete">
            <p class="text-xs text-slate-600">Irreversible: borra la cuenta, sus páginas, creaciones, monedas y pagos. Escribe el correo exacto para confirmar.</p>
            <input class="<?= ADMIN_INPUT_CLS ?>" name="confirm_email" required autocomplete="off" placeholder="<?= e((string) $user['email']) ?>">
            <?= $reasonInput ?>
            <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Eliminar definitivamente</button>
          </form>
        </details>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="lg:col-span-3 space-y-6">
    <section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
      <h2 class="font-semibold px-5 pt-5 pb-3">Páginas (<?= count($sites) ?>)</h2>
      <?php if (!$sites): echo admin_empty('Sin páginas'); else: echo admin_table_open(['Enlace', 'Plantilla', 'Vence', 'Estado']); ?>
        <?php foreach ($sites as $s): $on = !Access::isExpired($s['expires_at'] === null ? null : (string) $s['expires_at']); ?>
        <tr><td class="px-4 py-3 font-mono text-xs"><?= $on ? '<a class="text-rose-600 hover:underline" target="_blank" rel="noopener" href="' . e(url('c/' . $s['slug'])) . '">' . e((string) $s['slug']) . '</a>' : e((string) $s['slug']) ?></td>
          <td class="px-4 py-3"><?= e((string) $s['template_name']) ?></td><td class="px-4 py-3"><?= e(admin_date($s['expires_at'] === null ? null : (string) $s['expires_at'])) ?></td>
          <td class="px-4 py-3"><?= $on ? admin_badge('Activa', 'green') : admin_badge('Vencida', 'slate') ?></td></tr>
        <?php endforeach; echo admin_table_close(); endif; ?>
    </section>

    <section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
      <h2 class="font-semibold px-5 pt-5 pb-3">Últimos pagos</h2>
      <?php if (!$payments): echo admin_empty('Sin pagos'); else: echo admin_table_open(['Fecha', 'Concepto', 'Monto' => 'right', 'Estado']); ?>
        <?php foreach ($payments as $p): ?>
        <tr><td class="px-4 py-3"><?= e(admin_date((string) $p['created_at'])) ?></td>
          <td class="px-4 py-3"><?= e($p['tier_name'] ? 'Plan ' . $p['tier_name'] : ($p['template_name'] ? 'Plantilla ' . $p['template_name'] : ($p['coins'] ? $p['coins'] . ' monedas' : 'Pago'))) ?></td>
          <td class="px-4 py-3 text-right tabular"><?= e(admin_money((int) $p['amount_in_cents'])) ?></td><td class="px-4 py-3"><?= admin_status_badge((string) $p['status']) ?></td></tr>
        <?php endforeach; echo admin_table_close(); endif; ?>
    </section>

    <section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
      <h2 class="font-semibold px-5 pt-5 pb-3">Movimientos de monedas</h2>
      <?php if (!$coinTx): echo admin_empty('Sin movimientos'); else: echo admin_table_open(['Fecha', 'Motivo', 'Cambio' => 'right', 'Saldo' => 'right']); ?>
        <?php foreach ($coinTx as $c): ?>
        <tr><td class="px-4 py-3"><?= e(admin_date((string) $c['created_at'])) ?></td><td class="px-4 py-3"><?= e((string) $c['reason']) ?><span class="text-slate-400 text-xs"> <?= e((string) $c['ref']) ?></span></td>
          <td class="px-4 py-3 text-right tabular <?= (int) $c['delta'] < 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= sprintf('%+d', (int) $c['delta']) ?></td><td class="px-4 py-3 text-right tabular"><?= (int) $c['balance_after'] ?></td></tr>
        <?php endforeach; echo admin_table_close(); endif; ?>
    </section>

    <section class="<?= ADMIN_CARD_CLS ?>">
      <h2 class="font-semibold mb-3">Acciones de admin sobre este usuario</h2>
      <?php if (!$audit): echo admin_empty('Sin acciones registradas'); else: ?>
      <ul class="divide-y divide-slate-100 text-sm">
        <?php foreach ($audit as $a): ?>
        <li class="py-2"><span class="text-xs text-slate-500"><?= e(admin_date((string) $a['created_at'])) ?> · <?= e((string) ($a['admin_email'] ?? 'admin eliminado')) ?></span><br><?= e((string) $a['detail']) ?></li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
    </section>
  </div>
</div>
<?php admin_page_end();
