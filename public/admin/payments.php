<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_once ROOT . '/src/Payments.php';
require ROOT . '/src/admin_layout.php';

/** Pagos: KPIs, filtros, tabla paginada, aprobación manual, anulación y exportación CSV. */
$admin = Admin::guard();
$pdo = db();

$errors = [];

// ---- Acciones (POST): aprobar manualmente / anular, siempre con nota ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $action = (string) ($_POST['action'] ?? '');
    $pid    = (int) ($_POST['id'] ?? 0);
    $note   = trim((string) ($_POST['note'] ?? ''));
    $back   = 'admin/payments.php' . (preg_match('/^[A-Za-z0-9=&_%.:+\-]{1,300}$/', (string) ($_POST['back'] ?? '')) ? '?' . $_POST['back'] : '');

    if (!in_array($action, ['approve', 'void', 'delete'], true) || $pid <= 0) {
        flash('Acción inválida.');
        redirect($back);
    }
    if ($action === 'delete') {
        try {
            AdminUsers::deletePayment($pid, (string) ($_POST['confirm_ref'] ?? ''), $note, (int) $admin['id']);
            flash("Registro del pago #$pid eliminado.");
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage());
        }
        redirect($back);
    }
    if ($action === 'approve' && empty($_POST['confirm'])) {
        flash('Debes marcar la confirmación para aprobar manualmente.');
        redirect($back);
    }
    $r = $action === 'approve'
        ? Payments::approveManually($pdo, $pid, (int) $admin['id'], $note)
        : Payments::void($pdo, $pid, (int) $admin['id'], $note);
    if ($r['ok']) {
        $p = (array) $r['payment'];
        Admin::log($action === 'approve' ? 'payment.approve_manual' : 'payment.void',
            sprintf('pago #%d usuario #%d monto %s nota: %s', $pid, (int) $p['user_id'], admin_money((int) $p['amount_in_cents']), $note));
        flash($action === 'approve' ? "Pago #$pid aprobado manualmente y activado." : "Pago #$pid anulado.");
    } else {
        flash((string) $r['error']);
    }
    redirect($back);
}

// ---- Filtros ---------------------------------------------------------------------------------
$status = strtoupper((string) ($_GET['status'] ?? ''));
$status = in_array($status, ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true) ? $status : '';
$type   = in_array($_GET['type'] ?? '', ['tier', 'coins', 'template'], true) ? (string) $_GET['type'] : '';
$method = strtoupper((string) ($_GET['method'] ?? ''));
$method = in_array($method, Payments::METHODS, true) ? $method : '';
$dateOk = static fn(string $d): bool => ($x = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) !== false && $x->format('Y-m-d') === $d;
$from   = $dateOk((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$to     = $dateOk((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';
$q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$filters = ['status' => $status, 'type' => $type, 'method' => $method, 'from' => $from, 'to' => $to, 'q' => $q];

$where = [];
$args  = [];
if ($status !== '') { $where[] = 'p.status = ?'; $args[] = $status; }
if ($method !== '') { $where[] = 'p.method = ?'; $args[] = $method; }
if ($type === 'coins')    { $where[] = 'p.coins > 0'; }
if ($type === 'template') { $where[] = 'p.template_id IS NOT NULL'; }
if ($type === 'tier')     { $where[] = '(p.coins IS NULL OR p.coins = 0) AND p.template_id IS NULL'; }
// Las fechas del filtro son de El Salvador (UTC-6): se convierten a UTC.
if ($from !== '') { $where[] = 'p.created_at >= ?'; $args[] = (new DateTimeImmutable($from . ' 06:00:00'))->format('Y-m-d H:i:s'); }
if ($to !== '')   { $where[] = 'p.created_at < ?';  $args[] = (new DateTimeImmutable($to . ' 06:00:00'))->modify('+1 day')->format('Y-m-d H:i:s'); }
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = '(u.email LIKE ? OR p.reference LIKE ? OR p.wompi_transaction_id LIKE ?' . (ctype_digit($q) ? ' OR p.id = ?' : '') . ')';
    array_push($args, $like, $like, $like);
    if (ctype_digit($q)) { $args[] = (int) $q; }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from_sql = 'FROM payments p JOIN users u ON u.id = p.user_id
             LEFT JOIN membership_tiers t ON t.id = p.tier_id
             LEFT JOIN templates tp ON tp.id = p.template_id ' . $whereSql;
$cols = 'p.id, p.user_id, u.email, p.reference, p.amount_in_cents, p.status, p.method, p.coins, p.promo_code, p.admin_note,
         p.wompi_transaction_id, p.fulfilled_at, p.created_at, t.name AS tier_name, tp.name AS template_name';

$concept = static function (array $r): string {
    if ((int) $r['coins'] > 0) {
        return (int) $r['coins'] . ' monedas';
    }
    if ($r['template_name'] !== null) {
        return 'Plantilla: ' . $r['template_name'];
    }
    return 'Plan ' . ($r['tier_name'] ?? 'Eterno');
};

// ---- Exportar CSV -----------------------------------------------------------------------------
if (($_GET['export'] ?? '') === '1') {
    $st = $pdo->prepare("SELECT $cols $from_sql ORDER BY p.id DESC LIMIT 20000");
    $st->execute($args);
    Admin::log('payments.export', json_encode(array_filter($filters), JSON_UNESCAPED_UNICODE) ?: '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagos-' . gmdate('Ymd-His') . '.csv"');
    $safe = static fn(mixed $v): string => (($s = (string) $v) !== '' && str_contains('=+-@', $s[0])) ? "'" . $s : $s;
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'fecha_sv', 'correo', 'concepto', 'monto_usd', 'metodo', 'estado', 'referencia', 'wompi_tx', 'cupon', 'nota'], ',', '"', '');
    while ($r = $st->fetch()) {
        fputcsv($out, array_map($safe, [
            $r['id'], admin_date($r['created_at']), $r['email'], $concept($r), number_format((int) $r['amount_in_cents'] / 100, 2, '.', ''),
            $r['method'], $r['status'], $r['reference'], $r['wompi_transaction_id'], $r['promo_code'], $r['admin_note'],
        ]), ',', '"', '');
    }
    exit;
}

// ---- KPIs -------------------------------------------------------------------------------------
$sv = new DateTimeZone('America/El_Salvador');
$monthStart = (new DateTimeImmutable('first day of this month 00:00:00', $sv))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'APPROVED' AND method <> 'PROMO' AND created_at >= ? THEN amount_in_cents END), 0) AS revenue,
                            COALESCE(SUM(status = 'PENDING'), 0) AS pending,
                            COALESCE(SUM(status IN ('DECLINED', 'ERROR')), 0) AS declined,
                            COALESCE(SUM(method = 'PROMO'), 0) AS courtesy
                       FROM payments");
$st->execute([$monthStart]);
$k = $st->fetch();

// ---- Lista paginada ---------------------------------------------------------------------------
$st = $pdo->prepare("SELECT COUNT(*) $from_sql");
$st->execute($args);
$total = (int) $st->fetchColumn();
$perPage = 25;
$pages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$st = $pdo->prepare("SELECT $cols $from_sql ORDER BY p.id DESC LIMIT $perPage OFFSET " . ($page - 1) * $perPage);
$st->execute($args);
$rows = $st->fetchAll();

$query = array_filter($filters, static fn(string $v): bool => $v !== '');
$back = http_build_query($query + ($page > 1 ? ['page' => $page] : []));
$exportUrl = url('admin/payments.php') . '?' . http_build_query($query + ['export' => 1]);
$actions = '<a href="' . e($exportUrl) . '" class="' . ADMIN_BTN_GHOST_CLS . '">Exportar CSV</a>';

admin_page_start('Pagos', 'payments', 'Ingresos, cupones y aprobaciones manuales', $actions);
admin_errors($errors);

$sel = static fn(string $cur, string $v): string => $cur === $v ? ' selected' : '';
$methodTone = ['WOMPI' => 'blue', 'MANUAL' => 'violet', 'PROMO' => 'green'];

/** Nota + <details> de gestión (aprobar/anular), igual en la fila de tabla y en la tarjeta móvil. */
$manageCell = static function (array $r, string $back) use ($concept): string {
    $done = $r['fulfilled_at'] !== null || $r['status'] === 'APPROVED';
    $canApprove = in_array($r['status'], Payments::MANUAL_APPROVABLE, true) && !$done;
    $canVoid = !$done && $r['status'] !== 'VOIDED';
    $out = '';
    if ($r['admin_note']) {
        $out .= '<p class="text-xs text-slate-600 mb-1">' . e($r['admin_note']) . '</p>';
    }
    if (!$canApprove && !$canVoid) {
        return $out;
    }
    $out .= '<details class="text-sm"><summary class="cursor-pointer text-rose-700 font-semibold">Gestionar</summary>';
    if ($canApprove) {
        $out .= '<form method="post" class="mt-2 space-y-2 border border-slate-200 rounded-lg p-3">'
            . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<label class="block text-xs font-semibold" for="n-a' . (int) $r['id'] . '">Motivo (obligatorio)</label>'
            . '<input id="n-a' . (int) $r['id'] . '" name="note" required maxlength="255" placeholder="transferencia verificada" class="' . ADMIN_INPUT_CLS . '">'
            . '<details class="rounded bg-amber-50 border border-amber-200 p-2"><summary class="cursor-pointer text-xs font-semibold text-amber-900">Confirmar aprobación manual</summary>'
            . '<label class="flex items-start gap-2 text-xs mt-2"><input type="checkbox" name="confirm" value="1" required> Confirmo que el pago fue verificado y se activará ' . e($concept($r)) . ' para ' . e($r['email']) . '.</label>'
            . '<button name="action" value="approve" class="' . ADMIN_BTN_CLS . ' mt-2">Aprobar manualmente</button></details></form>';
    }
    if ($canVoid) {
        $out .= '<form method="post" class="mt-2 space-y-2 border border-slate-200 rounded-lg p-3">'
            . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<label class="block text-xs font-semibold" for="n-v' . (int) $r['id'] . '">Motivo de la anulación</label>'
            . '<input id="n-v' . (int) $r['id'] . '" name="note" required maxlength="255" class="' . ADMIN_INPUT_CLS . '">'
            . '<button name="action" value="void" class="' . ADMIN_BTN_DANGER_CLS . '">Anular</button></form>';
    }
    // Eliminar el registro (cualquier estado). No revierte lo ya aplicado: para eso está «Anular».
    $out .= '<form method="post" class="mt-2 space-y-2 border border-rose-200 rounded-lg p-3">'
        . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<p class="text-xs font-semibold text-rose-800">Eliminar registro (irreversible)</p>'
        . '<p class="text-xs text-slate-600">No revierte plan, monedas ni plantilla ya activados. Escribe la referencia <span class="font-mono">' . e((string) $r['reference']) . '</span> para confirmar.</p>'
        . '<input name="confirm_ref" required autocomplete="off" maxlength="60" aria-label="Referencia del pago" class="' . ADMIN_INPUT_CLS . ' font-mono">'
        . '<input name="note" required maxlength="200" placeholder="Motivo" aria-label="Motivo" class="' . ADMIN_INPUT_CLS . '">'
        . '<button name="action" value="delete" class="' . ADMIN_BTN_DANGER_CLS . '">Eliminar registro</button></form>';
    return $out . '</details>';
};
?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
  <?= admin_stat('Ingresos del mes', admin_money((int) $k['revenue']), 'Aprobados por pasarela o manual', 'payments', 'green') ?>
  <?= admin_stat('Pendientes', (string) (int) $k['pending'], 'Esperando confirmación', '', 'amber') ?>
  <?= admin_stat('Rechazados / error', (string) (int) $k['declined'], 'Histórico', '', 'rose') ?>
  <?= admin_stat('Cortesías (cupón 100 %)', (string) (int) $k['courtesy'], 'Sin cobro', '', 'violet') ?>
</div>

<form method="get" class="<?= ADMIN_CARD_CLS ?> mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
  <div><label class="block text-xs font-semibold mb-1" for="f-status">Estado</label>
    <select id="f-status" name="status" class="<?= ADMIN_INPUT_CLS ?>"><option value="">Todos</option>
    <?php foreach (['PENDING' => 'Pendiente', 'APPROVED' => 'Aprobado', 'DECLINED' => 'Rechazado', 'VOIDED' => 'Anulado', 'ERROR' => 'Error'] as $v => $l): ?>
      <option value="<?= $v ?>"<?= $sel($status, $v) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div><label class="block text-xs font-semibold mb-1" for="f-type">Tipo</label>
    <select id="f-type" name="type" class="<?= ADMIN_INPUT_CLS ?>"><option value="">Todos</option>
    <?php foreach (['tier' => 'Membresía', 'coins' => 'Monedas', 'template' => 'Plantilla'] as $v => $l): ?>
      <option value="<?= $v ?>"<?= $sel($type, $v) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div><label class="block text-xs font-semibold mb-1" for="f-method">Método</label>
    <select id="f-method" name="method" class="<?= ADMIN_INPUT_CLS ?>"><option value="">Todos</option>
    <?php foreach (Payments::METHODS as $m): ?><option value="<?= $m ?>"<?= $sel($method, $m) ?>><?= $m ?></option><?php endforeach; ?></select></div>
  <div><label class="block text-xs font-semibold mb-1" for="f-from">Desde</label>
    <input id="f-from" type="date" name="from" value="<?= e($from) ?>" class="<?= ADMIN_INPUT_CLS ?>"></div>
  <div><label class="block text-xs font-semibold mb-1" for="f-to">Hasta</label>
    <input id="f-to" type="date" name="to" value="<?= e($to) ?>" class="<?= ADMIN_INPUT_CLS ?>"></div>
  <div><label class="block text-xs font-semibold mb-1" for="f-q">Buscar</label>
    <input id="f-q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="correo, referencia, id Wompi" class="<?= ADMIN_INPUT_CLS ?>"></div>
  <div class="lg:col-span-6 flex gap-2">
    <button class="<?= ADMIN_BTN_CLS ?>">Filtrar</button>
    <a href="<?= e(url('admin/payments.php')) ?>" class="<?= ADMIN_BTN_GHOST_CLS ?>">Limpiar</a>
    <span class="ml-auto text-sm text-slate-500 self-center"><?= $total ?> pagos</span>
  </div>
</form>

<section class="<?= ADMIN_CARD_CLS ?> !p-0 overflow-hidden">
<?php if (!$rows): echo admin_empty('No hay pagos con esos filtros', 'Prueba a limpiar los filtros.'); else: ?>
  <div class="hidden sm:block">
    <?= admin_table_open(['#', 'Usuario', 'Concepto', 'Monto' => 'right', 'Método', 'Estado', 'Fecha (SV)', 'Nota / acciones']) ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="px-4 py-3 tabular">#<?= (int) $r['id'] ?></td>
      <td class="px-4 py-3"><a class="text-rose-700 hover:underline" href="<?= e(url('admin/user.php?id=' . (int) $r['user_id'])) ?>"><?= e($r['email']) ?></a></td>
      <td class="px-4 py-3"><?= e($concept($r)) ?><?= $r['promo_code'] ? ' ' . admin_badge((string) $r['promo_code'], 'violet') : '' ?></td>
      <td class="px-4 py-3 text-right tabular"><?= e(admin_money((int) $r['amount_in_cents'])) ?></td>
      <td class="px-4 py-3"><?= admin_badge((string) $r['method'], $methodTone[$r['method']] ?? 'slate') ?></td>
      <td class="px-4 py-3"><?= admin_status_badge((string) $r['status']) ?></td>
      <td class="px-4 py-3 whitespace-nowrap"><?= e(admin_date($r['created_at'])) ?></td>
      <td class="px-4 py-3 max-w-xs"><?= $manageCell($r, $back) ?></td>
    </tr>
    <?php endforeach; echo admin_table_close(); ?>
  </div>
  <ul class="sm:hidden divide-y divide-slate-100">
    <?php foreach ($rows as $r): ?>
    <li class="p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <a class="font-semibold text-rose-700 hover:underline break-all" href="<?= e(url('admin/user.php?id=' . (int) $r['user_id'])) ?>"><?= e($r['email']) ?></a>
          <p class="text-xs text-slate-500 mt-0.5">#<?= (int) $r['id'] ?> · <?= e($concept($r)) ?><?= $r['promo_code'] ? ' ' . admin_badge((string) $r['promo_code'], 'violet') : '' ?></p>
        </div>
        <p class="shrink-0 text-right font-semibold tabular"><?= e(admin_money((int) $r['amount_in_cents'])) ?></p>
      </div>
      <div class="flex flex-wrap items-center gap-2 mt-2">
        <?= admin_badge((string) $r['method'], $methodTone[$r['method']] ?? 'slate') ?>
        <?= admin_status_badge((string) $r['status']) ?>
        <span class="text-xs text-slate-500 tabular ml-auto"><?= e(admin_date($r['created_at'])) ?></span>
      </div>
      <div class="mt-2"><?= $manageCell($r, $back) ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?= admin_pager($page, $pages, 'admin/payments.php', $query) ?>
<?php admin_page_end();
