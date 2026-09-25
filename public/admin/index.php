<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Dashboard de logística: cifras del negocio. Solo lectura. */
Admin::guard();
$pdo = db();

$scalar = static function (string $sql, array $args = []) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn();
};

// Límites de mes/día en hora de El Salvador, convertidos a UTC para consultar.
$sv  = new DateTimeZone('America/El_Salvador');
$utc = new DateTimeZone('UTC');
$nowSv       = new DateTimeImmutable('now', $sv);
$fmt         = static fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');
$monthStart  = $fmt($nowSv->modify('first day of this month')->setTime(0, 0));
$prevStart   = $fmt($nowSv->modify('first day of last month')->setTime(0, 0));
$todayStart  = $fmt($nowSv->setTime(0, 0));

$revRange = "SELECT COALESCE(SUM(amount_in_cents), 0) FROM payments WHERE status = 'APPROVED' AND created_at >= ? AND created_at < ?";
$revMonth = $scalar($revRange, [$monthStart, '9999-12-31 00:00:00']);
$revPrev  = $scalar($revRange, [$prevStart, $monthStart]);
$variation = $revPrev > 0 ? round(($revMonth - $revPrev) / $revPrev * 100) : null;
$paidToday = $scalar("SELECT COUNT(*) FROM payments WHERE status = 'APPROVED' AND created_at >= ?", [$todayStart]);
$stale     = $scalar("SELECT COUNT(*) FROM payments WHERE status = 'PENDING' AND created_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");

$users    = $scalar('SELECT COUNT(*) FROM users');
$newUsers = $scalar('SELECT COUNT(*) FROM users WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY');
$coins    = $scalar('SELECT COALESCE(SUM(coins), 0) FROM users');

$sitesActive = $scalar('SELECT COUNT(*) FROM user_sites WHERE expires_at IS NULL OR expires_at > UTC_TIMESTAMP()');
$sitesMonth  = $scalar('SELECT COUNT(*) FROM user_sites WHERE created_at >= ?', [$monthStart]);
$sitesSoon   = $scalar('SELECT COUNT(*) FROM user_sites WHERE expires_at > UTC_TIMESTAMP() AND expires_at <= UTC_TIMESTAMP() + INTERVAL 48 HOUR');

$plans = $pdo->query(
    'SELECT t.name, COUNT(u.id) AS total
       FROM membership_tiers t
       LEFT JOIN users u ON u.membership_tier_id = t.id AND (u.membership_expires_at IS NULL OR u.membership_expires_at > UTC_TIMESTAMP())
      GROUP BY t.id ORDER BY t.sort_order'
)->fetchAll();

// Series de 30 días (día local de El Salvador). Se rellenan los días sin datos con 0.
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $days[$nowSv->modify("-$i days")->format('Y-m-d')] = 0;
}
$since = $fmt($nowSv->modify('-29 days')->setTime(0, 0));
$series = static function (string $sql) use ($pdo, $since, $days): array {
    $st = $pdo->prepare($sql);
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        if (array_key_exists($r['d'], $days)) {
            $days[$r['d']] = (int) $r['v'];
        }
    }
    return $days;
};
$revSeries = $series("SELECT DATE(created_at - INTERVAL 6 HOUR) AS d, SUM(amount_in_cents) AS v FROM payments WHERE status = 'APPROVED' AND created_at >= ? GROUP BY d");
$regSeries = $series('SELECT DATE(created_at - INTERVAL 6 HOUR) AS d, COUNT(*) AS v FROM users WHERE created_at >= ? GROUP BY d');

/** Barras SVG inline. $money: formatea los valores como USD. */
function dash_bars(array $data, string $label, bool $money, string $color): string
{
    $w = 600; $h = 120; $n = count($data);
    $max = max(1, max($data));
    $bw = $w / $n;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-full h-32" role="img" aria-label="' . e($label) . '" preserveAspectRatio="none">';
    $i = 0;
    foreach ($data as $day => $v) {
        $bh = $v > 0 ? max(3, $v / $max * ($h - 6)) : 1.5;
        $svg .= '<rect x="' . round($i * $bw + 1.5, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw - 3, 2) . '" height="' . round($bh, 2)
            . '" rx="2" fill="' . ($v > 0 ? $color : '#e2e8f0') . '"><title>' . e($day . ': ' . ($money ? admin_money($v) : (string) $v)) . '</title></rect>';
        $i++;
    }
    return $svg . '</svg>';
}

function dash_table(array $data, bool $money, string $col): string
{
    $out = '<details class="mt-3 text-xs text-slate-600"><summary class="cursor-pointer text-slate-500 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 rounded">Ver como tabla</summary>'
        . '<div class="max-h-48 overflow-y-auto mt-2">' . admin_table_open(['Día', $col => 'right']);
    foreach (array_reverse($data, true) as $day => $v) {
        $out .= '<tr><td class="px-4 py-1.5 tabular">' . e(date('d/m/Y', strtotime($day))) . '</td><td class="px-4 py-1.5 text-right tabular">' . e($money ? admin_money($v) : (string) $v) . '</td></tr>';
    }
    return $out . admin_table_close() . '</div></details>';
}

$recentPayments = $pdo->query(
    'SELECT p.reference, p.amount_in_cents, p.status, p.promo_code, p.created_at, u.email
       FROM payments p JOIN users u ON u.id = p.user_id
      ORDER BY p.id DESC LIMIT 8'
)->fetchAll();

$topTemplates = $pdo->query(
    'SELECT t.name, t.is_premium, COUNT(s.id) AS total
       FROM templates t LEFT JOIN user_sites s ON s.template_id = t.id
      GROUP BY t.id ORDER BY total DESC, t.id LIMIT 6'
)->fetchAll();
$topMax = max(1, (int) ($topTemplates[0]['total'] ?? 0));

$audit = $pdo->query(
    'SELECT a.action, a.detail, a.created_at, u.email
       FROM admin_audit a LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 6'
)->fetchAll();

$varText = $variation === null ? 'Sin datos del mes anterior' : ($variation >= 0 ? '+' : '') . (int) $variation . ' % vs. mes anterior (' . admin_money($revPrev) . ')';

admin_page_start('Resumen', 'index', 'Cómo va el negocio hoy, en hora de El Salvador.');
?>

<?php if ($stale > 0): ?>
  <div role="alert" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    <p><strong><?= $stale ?></strong> <?= $stale === 1 ? 'pago lleva' : 'pagos llevan' ?> más de 30 minutos pendiente<?= $stale === 1 ? '' : 's' ?>. Puede que el cliente pagó y el aviso no llegó.</p>
    <a class="<?= ADMIN_BTN_CLS ?>" href="<?= e(url('admin/payments.php?status=PENDING')) ?>">Revisar pagos pendientes</a>
  </div>
<?php endif; ?>

<section aria-label="Cifras principales" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?= admin_stat('Ingresos del mes', admin_money($revMonth), $varText, 'payments', $variation !== null && $variation < 0 ? 'rose' : 'green') ?>
  <?= admin_stat('Pagos aprobados hoy', (string) $paidToday, $stale > 0 ? $stale . ' pendiente(s) atrasado(s)' : 'Sin pagos atrasados', 'payments', $stale > 0 ? 'amber' : 'blue') ?>
  <?= admin_stat('Usuarios', number_format($users), '+' . $newUsers . ' en los últimos 7 días', 'users', 'blue') ?>
  <?= admin_stat('Monedas en circulación', number_format($coins), 'Suma de saldos de todos los usuarios', 'coins', 'violet') ?>
  <?= admin_stat('Páginas activas', number_format($sitesActive), $sitesMonth . ' creadas este mes', 'pages', 'green') ?>
  <?= admin_stat('Por vencer en 48 h', (string) $sitesSoon, $sitesSoon > 0 ? 'Buen momento para avisar y ofrecer renovar' : 'Ninguna página vence pronto', 'pages', $sitesSoon > 0 ? 'amber' : 'slate') ?>
  <div class="<?= ADMIN_CARD_CLS ?> sm:col-span-2">
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Membresías vigentes</p>
    <ul class="mt-3 grid grid-cols-3 gap-3 text-center">
      <?php foreach ($plans as $p): ?>
        <li class="rounded-xl bg-slate-50 py-2">
          <p class="text-xl font-bold text-slate-900 tabular"><?= (int) $p['total'] ?></p>
          <p class="text-xs text-slate-500"><?= e($p['name']) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="grid gap-6 lg:grid-cols-2 mt-6" aria-label="Últimos 30 días">
  <div class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold text-slate-900">Ingresos, 30 días</h2>
    <p class="text-xs text-slate-500 mb-3">Pagos aprobados por día · total <?= e(admin_money(array_sum($revSeries))) ?></p>
    <?= dash_bars($revSeries, 'Ingresos diarios de los últimos 30 días, total ' . admin_money(array_sum($revSeries)), true, '#e11d48') ?>
    <?= dash_table($revSeries, true, 'Ingresos') ?>
  </div>
  <div class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold text-slate-900">Registros, 30 días</h2>
    <p class="text-xs text-slate-500 mb-3">Usuarios nuevos por día · total <?= array_sum($regSeries) ?></p>
    <?= dash_bars($regSeries, 'Registros diarios de los últimos 30 días, total ' . array_sum($regSeries), false, '#2563eb') ?>
    <?= dash_table($regSeries, false, 'Registros') ?>
  </div>
</section>

<section class="grid gap-6 lg:grid-cols-3 mt-6">
  <div class="<?= ADMIN_CARD_CLS ?> lg:col-span-2 !p-0 overflow-hidden">
    <div class="flex items-center justify-between px-5 pt-5 pb-3">
      <h2 class="font-semibold text-slate-900">Últimos pagos</h2>
      <a class="text-sm font-semibold text-rose-700 hover:underline" href="<?= e(url('admin/payments.php')) ?>">Ver todos</a>
    </div>
    <?php if (!$recentPayments): ?>
      <?= admin_empty('Todavía no hay pagos', 'Cuando alguien compre monedas o un plan aparecerá aquí.') ?>
    <?php else: ?>
      <?= admin_table_open(['Usuario', 'Monto' => 'right', 'Estado', 'Fecha'], ['caption' => 'Últimos pagos']) ?>
        <?php foreach ($recentPayments as $p): ?>
          <tr>
            <td class="px-4 py-3 max-w-[14rem] truncate" title="<?= e($p['reference']) ?>">
              <a class="hover:underline" href="<?= e(url('admin/payments.php?q=' . rawurlencode((string) $p['reference']))) ?>"><?= e($p['email']) ?></a>
            </td>
            <td class="px-4 py-3 text-right tabular"><?= e(admin_money((int) $p['amount_in_cents'])) ?>
              <?php if ($p['promo_code']): ?><span class="block text-xs text-slate-400"><?= e($p['promo_code']) ?></span><?php endif; ?>
            </td>
            <td class="px-4 py-3"><?= admin_status_badge((string) $p['status']) ?></td>
            <td class="px-4 py-3 text-slate-500 tabular whitespace-nowrap"><?= e(admin_date($p['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?= admin_table_close() ?>
    <?php endif; ?>
  </div>

  <div class="space-y-6">
    <div class="<?= ADMIN_CARD_CLS ?>">
      <h2 class="font-semibold text-slate-900 mb-3">Acciones rápidas</h2>
      <ul class="space-y-2 text-sm">
        <li><a class="<?= ADMIN_BTN_GHOST_CLS ?> w-full !justify-start" href="<?= e(url('admin/payments.php?status=PENDING')) ?>">Aprobar pago pendiente</a></li>
        <li><a class="<?= ADMIN_BTN_GHOST_CLS ?> w-full !justify-start" href="<?= e(url('admin/promos.php#nuevo')) ?>">Crear cupón 100 %</a></li>
        <li><a class="<?= ADMIN_BTN_GHOST_CLS ?> w-full !justify-start" href="<?= e(url('admin/users.php')) ?>">Ver usuarios</a></li>
        <li><a class="<?= ADMIN_BTN_GHOST_CLS ?> w-full !justify-start" href="<?= e(url('admin/pages.php')) ?>">Moderar páginas</a></li>
      </ul>
    </div>

    <div class="<?= ADMIN_CARD_CLS ?>">
      <h2 class="font-semibold text-slate-900 mb-3">Plantillas más usadas</h2>
      <?php if (!$topTemplates): ?>
        <?= admin_empty('Aún no hay plantillas') ?>
      <?php else: ?>
        <ul class="space-y-3 text-sm">
          <?php foreach ($topTemplates as $t): ?>
            <li>
              <div class="flex justify-between gap-2">
                <span class="truncate"><?= e($t['name']) ?> <?= $t['is_premium'] ? admin_badge('Premium', 'amber') : '' ?></span>
                <span class="font-semibold tabular"><?= (int) $t['total'] ?></span>
              </div>
              <div class="mt-1 h-1.5 rounded-full bg-slate-100" aria-hidden="true"><div class="h-1.5 rounded-full bg-rose-400" style="width: <?= (int) round((int) $t['total'] / $topMax * 100) ?>%"></div></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="<?= ADMIN_CARD_CLS ?> mt-6">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold text-slate-900">Actividad administrativa reciente</h2>
    <a class="text-sm font-semibold text-rose-700 hover:underline" href="<?= e(url('admin/activity.php')) ?>">Ver todo</a>
  </div>
  <?php if (!$audit): ?>
    <?= admin_empty('Sin actividad registrada', 'Aquí verás quién cambió qué en el panel.') ?>
  <?php else: ?>
    <ul class="text-sm divide-y divide-slate-100">
      <?php foreach ($audit as $a): ?>
        <li class="py-2 flex flex-wrap gap-x-3 gap-y-1">
          <span class="text-slate-500 shrink-0 tabular"><?= e(admin_date($a['created_at'])) ?></span>
          <?= admin_badge((string) $a['action'], 'slate') ?>
          <span class="text-slate-600 truncate min-w-0 flex-1"><?= e($a['detail']) ?></span>
          <span class="text-slate-400 shrink-0"><?= e($a['email'] ?? '—') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php admin_page_end();
