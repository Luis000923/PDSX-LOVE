<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Moderación de páginas de todos los usuarios. */
$admin = Admin::guard();
$errors = [];
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$filter = (string) ($_GET['filter'] ?? 'all');
if (!isset(AdminUsers::PAGE_FILTERS[$filter])) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try {
        AdminUsers::deletePage((int) ($_POST['site_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (int) $admin['id']);
        flash('Página eliminada.');
        redirect('admin/pages.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''));
    } catch (InvalidArgumentException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$res = AdminUsers::pages($q, $filter, (int) ($_GET['page'] ?? 1));
admin_page_start('Páginas', 'pages', $res['total'] . ' resultado(s)');
admin_errors($errors);
?>
<form method="get" class="flex gap-2 mb-4">
  <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
  <input class="<?= ADMIN_INPUT_CLS ?> flex-1 min-w-0" type="search" name="q" value="<?= e($q) ?>" placeholder="Enlace, correo del dueño o nombres" aria-label="Buscar página">
  <button class="<?= ADMIN_BTN_CLS ?>">Buscar</button>
</form>
<nav class="flex flex-wrap gap-2 mb-6" aria-label="Filtros">
  <?php foreach (AdminUsers::PAGE_FILTERS as $key => $label): $on = $key === $filter; ?>
    <a href="<?= e(url('admin/pages.php') . '?' . http_build_query(array_filter(['q' => $q, 'filter' => $key === 'all' ? '' : $key]))) ?>"
       <?= $on ? 'aria-current="page"' : '' ?> class="rounded-full px-3.5 py-1.5 text-sm font-semibold border <?= $on ? 'bg-slate-900 border-slate-900 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
<?php if (!$res['rows']): echo admin_empty('Sin páginas', 'Prueba con otro filtro o búsqueda.'); else:
    echo admin_table_open(['Enlace', 'Plantilla', 'Dueño', 'Creada', 'Vence', 'Estado', 'Acciones'], ['caption' => 'Páginas']); ?>
  <?php foreach ($res['rows'] as $r): $on = (int) $r['is_active'] === 1 && (int) $r['is_suspended'] === 0; ?>
  <tr>
    <td class="px-4 py-3 font-mono text-xs"><?= e((string) $r['slug']) ?></td>
    <td class="px-4 py-3"><?= e((string) $r['template_name']) ?></td>
    <td class="px-4 py-3"><a class="text-rose-600 hover:underline" href="<?= e(url('admin/user.php?id=' . (int) $r['user_id'])) ?>"><?= e((string) $r['email']) ?></a></td>
    <td class="px-4 py-3"><?= e(admin_date((string) $r['created_at'])) ?></td>
    <td class="px-4 py-3"><?= e(admin_date($r['expires_at'] === null ? null : (string) $r['expires_at'])) ?></td>
    <td class="px-4 py-3"><?= (int) $r['is_suspended'] ? admin_badge('Dueño suspendido', 'rose') : ((int) $r['is_active'] ? admin_badge('Activa', 'green') : admin_badge('Vencida', 'slate')) ?></td>
    <td class="px-4 py-3">
      <?php if ($on): ?><a class="<?= ADMIN_BTN_GHOST_CLS ?> !py-1 mb-1" target="_blank" rel="noopener" href="<?= e(url('c/' . $r['slug'])) ?>">Ver</a><?php endif; ?>
      <details>
        <summary class="cursor-pointer text-xs font-semibold text-rose-700 list-none">Eliminar página</summary>
        <form method="post" class="mt-2 space-y-2 w-56"><?= csrf_field() ?><input type="hidden" name="site_id" value="<?= (int) $r['id'] ?>">
          <input class="<?= ADMIN_INPUT_CLS ?>" name="reason" maxlength="200" required placeholder="Motivo (obligatorio)">
          <button class="<?= ADMIN_BTN_DANGER_CLS ?> !py-1">Confirmar eliminación</button>
        </form>
      </details>
    </td>
  </tr>
  <?php endforeach; echo admin_table_close(); endif; ?>
</section>
<?= admin_pager($res['page'], $res['pages'], 'admin/pages.php', ['q' => $q, 'filter' => $filter === 'all' ? '' : $filter]) ?>
<?php admin_page_end();
