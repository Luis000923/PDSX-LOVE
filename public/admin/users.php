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
  <input class="<?= ADMIN_INPUT_CLS ?>" type="search" name="q" value="<?= e($q) ?>" placeholder="Correo o ID" aria-label="Buscar usuario">
  <button class="<?= ADMIN_BTN_CLS ?>">Buscar</button>
</form>
<nav class="flex flex-wrap gap-2 mb-6" aria-label="Filtros">
  <?php foreach (AdminUsers::FILTERS as $key => $label): $on = $key === $filter; ?>
    <a href="<?= e(url('admin/users.php') . '?' . http_build_query(array_filter(['q' => $q, 'filter' => $key === 'all' ? '' : $key]))) ?>"
       <?= $on ? 'aria-current="page"' : '' ?> class="rounded-full px-3.5 py-1.5 text-sm font-semibold border <?= $on ? 'bg-slate-900 border-slate-900 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
<?php if (!$res['rows']): echo admin_empty('Sin usuarios', 'Prueba con otro filtro o búsqueda.'); else:
    echo admin_table_open(['Usuario', 'Plan', 'Monedas' => 'right', 'Páginas' => 'right', 'Alta', 'Estado'], ['caption' => 'Usuarios']); ?>
  <?php foreach ($res['rows'] as $r): ?>
  <tr>
    <td class="px-4 py-3"><a class="font-semibold text-slate-900 hover:text-rose-600" href="<?= e(url('admin/user.php?id=' . (int) $r['id'])) ?>"><?= e((string) $r['email']) ?></a><br><span class="text-xs text-slate-400">#<?= (int) $r['id'] ?></span></td>
    <td class="px-4 py-3"><?php
        if ($r['plan_name'] !== null) { echo admin_badge((string) $r['plan_name'], 'violet') . '<br><span class="text-xs text-slate-500">' . ($r['membership_expires_at'] === null ? 'No vence' : e(admin_date((string) $r['membership_expires_at'], false))) . '</span>'; }
        elseif ($r['plan_expired']) { echo admin_badge('Vencido', 'amber') . '<br><span class="text-xs text-slate-500">' . e(admin_date((string) $r['membership_expires_at'], false)) . '</span>'; }
        else { echo admin_badge('Gratuito', 'slate'); } ?></td>
    <td class="px-4 py-3 text-right tabular"><?= (int) $r['coins'] ?></td>
    <td class="px-4 py-3 text-right tabular"><?= (int) $r['active_sites'] ?></td>
    <td class="px-4 py-3"><?= e(admin_date((string) $r['created_at'], false)) ?></td>
    <td class="px-4 py-3 space-x-1"><?= (int) $r['is_suspended'] ? admin_badge('Suspendida', 'rose') : '' ?><?= (int) $r['is_admin'] ? admin_badge('Admin', 'blue') : '' ?><?= (int) $r['has_pending'] ? admin_badge('Pago pendiente', 'amber') : '' ?></td>
  </tr>
  <?php endforeach; echo admin_table_close(); endif; ?>
</section>
<?= admin_pager($res['page'], $res['pages'], 'admin/users.php', ['q' => $q, 'filter' => $filter === 'all' ? '' : $filter]) ?>
<?php admin_page_end();
