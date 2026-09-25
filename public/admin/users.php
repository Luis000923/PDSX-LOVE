<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Listado y búsqueda de usuarios. */
Admin::guard();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$filter = (string) ($_GET['filter'] ?? 'all');
if (!isset(AdminUsers::FILTERS[$filter])) {
    $filter = 'all';
}
$res = AdminUsers::search($q, $filter, (int) ($_GET['page'] ?? 1));

admin_page_start('Usuarios', 'users', $res['total'] . ' resultado(s)');
?>
<form method="get" class="flex gap-2 mb-4">
  <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
  <input class="<?= ADMIN_INPUT_CLS ?> flex-1 min-w-0" type="search" name="q" value="<?= e($q) ?>" placeholder="Correo o ID" aria-label="Buscar usuario">
  <button class="<?= ADMIN_BTN_CLS ?>">Buscar</button>
</form>
<nav class="flex flex-wrap gap-2 mb-6" aria-label="Filtros">
  <?php foreach (AdminUsers::FILTERS as $key => $label): $on = $key === $filter; ?>
    <a href="<?= e(url('admin/users.php') . '?' . http_build_query(array_filter(['q' => $q, 'filter' => $key === 'all' ? '' : $key]))) ?>"
       <?= $on ? 'aria-current="page"' : '' ?> class="rounded-full px-3.5 py-1.5 text-sm font-semibold border <?= $on ? 'bg-slate-900 border-slate-900 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php
$planCell = static function (array $r): string {
    if ($r['plan_name'] !== null) {
        return admin_badge((string) $r['plan_name'], 'violet') . '<br><span class="text-xs text-slate-500">' . ($r['membership_expires_at'] === null ? 'No vence' : e(admin_date((string) $r['membership_expires_at'], false))) . '</span>';
    }
    if ($r['plan_expired']) {
        return admin_badge('Vencido', 'amber') . '<br><span class="text-xs text-slate-500">' . e(admin_date((string) $r['membership_expires_at'], false)) . '</span>';
    }
    return admin_badge('Gratuito', 'slate');
};
$statusCell = static fn(array $r): string => ((int) $r['is_suspended'] ? admin_badge('Suspendida', 'rose') . ' ' : '')
    . ((int) $r['is_admin'] ? admin_badge('Admin', 'blue') . ' ' : '')
    . ((int) $r['has_pending'] ? admin_badge('Pago pendiente', 'amber') : '');
?>
<section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
<?php if (!$res['rows']): echo admin_empty('Sin usuarios', 'Prueba con otro filtro o búsqueda.'); else: ?>
  <div class="hidden sm:block">
    <?= admin_table_open(['Usuario', 'Plan', 'Monedas' => 'right', 'Páginas' => 'right', 'Alta', 'Estado'], ['caption' => 'Usuarios']) ?>
    <?php foreach ($res['rows'] as $r): ?>
    <tr>
      <td class="px-4 py-3"><a class="font-semibold text-slate-900 hover:text-rose-600" href="<?= e(url('admin/user.php?id=' . (int) $r['id'])) ?>"><?= e((string) $r['email']) ?></a><br><span class="text-xs text-slate-400">#<?= (int) $r['id'] ?></span></td>
      <td class="px-4 py-3"><?= $planCell($r) ?></td>
      <td class="px-4 py-3 text-right tabular"><?= (int) $r['coins'] ?></td>
      <td class="px-4 py-3 text-right tabular"><?= (int) $r['active_sites'] ?></td>
      <td class="px-4 py-3"><?= e(admin_date((string) $r['created_at'], false)) ?></td>
      <td class="px-4 py-3 space-x-1"><?= $statusCell($r) ?></td>
    </tr>
    <?php endforeach; echo admin_table_close(); ?>
  </div>
  <ul class="sm:hidden divide-y divide-slate-100">
    <?php foreach ($res['rows'] as $r): ?>
    <li class="p-4">
      <a class="font-semibold text-slate-900 hover:text-rose-600 break-all" href="<?= e(url('admin/user.php?id=' . (int) $r['id'])) ?>"><?= e((string) $r['email']) ?></a>
      <p class="text-xs text-slate-400 mt-0.5">#<?= (int) $r['id'] ?> · alta <?= e(admin_date((string) $r['created_at'], false)) ?></p>
      <div class="flex flex-wrap items-center gap-2 mt-2 text-sm"><?= $planCell($r) ?></div>
      <p class="text-xs text-slate-500 mt-2"><?= (int) $r['coins'] ?> monedas · <?= (int) $r['active_sites'] ?> página(s)</p>
      <?php if ($statusCell($r) !== ''): ?><div class="flex flex-wrap gap-1 mt-2"><?= $statusCell($r) ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?= admin_pager($res['page'], $res['pages'], 'admin/users.php', ['q' => $q, 'filter' => $filter === 'all' ? '' : $filter]) ?>
<?php admin_page_end();
