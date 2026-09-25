<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Registro de acciones de administración (admin_audit), paginado y filtrable. Solo lectura. */
Admin::guard();
$pdo = db();

const ACTIVITY_PER_PAGE = 30;

$action = trim((string) ($_GET['action'] ?? ''));
$q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page   = max(1, (int) ($_GET['page'] ?? 1));

$actions = $pdo->query('SELECT DISTINCT action FROM admin_audit ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
if ($action !== '' && !in_array($action, $actions, true)) {
    $action = '';
}

$where = [];
$args  = [];
if ($action !== '') {
    $where[] = 'a.action = ?';
    $args[]  = $action;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(a.detail LIKE ? OR u.email LIKE ? OR a.ip LIKE ?)';
    array_push($args, $like, $like, $like);
}
$filtered = $where !== [];
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM admin_audit a LEFT JOIN users u ON u.id = a.user_id $w");
$st->execute($args);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / ACTIVITY_PER_PAGE));
$page  = min($page, $pages);

$st = $pdo->prepare(
    "SELECT a.action, a.detail, a.ip, a.created_at, u.email
       FROM admin_audit a LEFT JOIN users u ON u.id = a.user_id
       $w ORDER BY a.id DESC LIMIT " . ACTIVITY_PER_PAGE . ' OFFSET ' . (($page - 1) * ACTIVITY_PER_PAGE)
);
$st->execute($args);
$rows = $st->fetchAll();

$tone = static fn(string $a): string => match (true) {
    $a === 'denied'                        => 'rose',
    str_contains($a, 'delete')             => 'rose',
    str_contains($a, 'create')             => 'green',
    str_contains($a, 'update'), str_contains($a, 'toggle'), str_contains($a, 'active'), str_contains($a, 'premium') => 'blue',
    default                                => 'slate',
};

admin_page_start('Actividad', 'activity', 'Quién hizo qué en el panel. Hora de El Salvador.');
?>
<section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
  <form method="get" class="flex flex-wrap items-end gap-3 p-4 border-b border-slate-100">
    <div>
      <label class="block text-xs font-semibold text-slate-600 mb-1" for="action">Acción</label>
      <select id="action" name="action" class="<?= ADMIN_INPUT_CLS ?>">
        <option value="">Todas</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex-1 min-w-[12rem]">
      <label class="block text-xs font-semibold text-slate-600 mb-1" for="q">Buscar</label>
      <input id="q" name="q" type="search" maxlength="100" class="<?= ADMIN_INPUT_CLS ?>" placeholder="Detalle, correo o IP" value="<?= e($q) ?>">
    </div>
    <button class="<?= ADMIN_BTN_CLS ?>">Filtrar</button>
    <?php if ($filtered): ?>
      <a class="<?= ADMIN_BTN_GHOST_CLS ?>" href="<?= e(url('admin/activity.php')) ?>">Limpiar</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <?= admin_empty($filtered ? 'Ningún registro coincide' : 'Aún no hay actividad', $filtered ? 'Prueba con otra acción o quita el texto de búsqueda.' : 'Las acciones del panel quedarán registradas aquí.') ?>
  <?php else: ?>
    <?= admin_table_open(['Fecha', 'Acción', 'Detalle', 'Admin', 'IP'], ['caption' => 'Registro de actividad']) ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-3 text-slate-500 tabular whitespace-nowrap"><?= e(admin_date($r['created_at'])) ?></td>
          <td class="px-4 py-3"><?= admin_badge((string) $r['action'], $tone((string) $r['action'])) ?></td>
          <td class="px-4 py-3 text-slate-700 break-words min-w-[14rem]"><?= e($r['detail']) ?></td>
          <td class="px-4 py-3 text-slate-600"><?= e($r['email'] ?? '—') ?></td>
          <td class="px-4 py-3 text-slate-400 tabular"><?= e($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
    <?= admin_table_close() ?>
  <?php endif; ?>
</section>
<p class="text-xs text-slate-500 mt-3"><?= number_format($total) ?> registro(s)</p>
<?= admin_pager($page, $pages, 'admin/activity.php', ['action' => $action, 'q' => $q]) ?>
<?php admin_page_end();
