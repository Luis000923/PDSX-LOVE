<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Dashboard: cifras rápidas del negocio. Solo lectura. */
Admin::guard();
$pdo = db();

$scalar = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();

$stats = [
    'users'          => $scalar('SELECT COUNT(*) FROM users'),
    'premium'        => $scalar('SELECT COUNT(*) FROM users WHERE is_premium = 1'),
    'sites'          => $scalar('SELECT COUNT(*) FROM user_sites'),
    'templates'      => $scalar('SELECT COUNT(*) FROM templates WHERE is_active = 1'),
    'paid'           => $scalar("SELECT COUNT(*) FROM payments WHERE status = 'APPROVED'"),
    'pending'        => $scalar("SELECT COUNT(*) FROM payments WHERE status = 'PENDING'"),
    'failed'         => $scalar("SELECT COUNT(*) FROM payments WHERE status IN ('DECLINED','VOIDED','ERROR')"),
    'revenue_cents'  => $scalar("SELECT COALESCE(SUM(amount_in_cents), 0) FROM payments WHERE status = 'APPROVED'"),
];

$conversion = $stats['users'] > 0 ? round($stats['premium'] / $stats['users'] * 100, 1) : 0.0;
$money = static fn(int $cents): string => '$' . number_format($cents / 100, 0, ',', '.');

$recentPayments = $pdo->query(
    'SELECT p.reference, p.amount_in_cents, p.status, p.promo_code, p.created_at, u.email
       FROM payments p JOIN users u ON u.id = p.user_id
      ORDER BY p.id DESC LIMIT 10'
)->fetchAll();

$topTemplates = $pdo->query(
    'SELECT t.name, t.is_premium, COUNT(s.id) AS total
       FROM templates t LEFT JOIN user_sites s ON s.template_id = t.id
      GROUP BY t.id ORDER BY total DESC, t.id LIMIT 6'
)->fetchAll();

$audit = $pdo->query(
    'SELECT a.action, a.detail, a.created_at, u.email
       FROM admin_audit a LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 8'
)->fetchAll();

$cards = [
    ['Usuarios registrados', (string) $stats['users'],            $stats['premium'] . ' premium · ' . $conversion . '% conversión'],
    ['Páginas de pareja',    (string) $stats['sites'],            $stats['templates'] . ' plantillas activas'],
    ['Pagos aprobados',      (string) $stats['paid'],             $stats['pending'] . ' pendientes · ' . $stats['failed'] . ' fallidos'],
    ['Ingresos (Wompi)',     $money($stats['revenue_cents']),     'Solo transacciones APPROVED'],
];

admin_page_start('Dashboard', 'index');
?>
<h1 class="text-2xl font-bold mb-6">Resumen</h1>

<section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php foreach ($cards as [$label, $value, $hint]): ?>
    <div class="<?= ADMIN_CARD_CLS ?>">
      <p class="text-xs uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
      <p class="text-3xl font-bold mt-1"><?= e($value) ?></p>
      <p class="text-xs text-slate-500 mt-1"><?= e($hint) ?></p>
    </div>
  <?php endforeach; ?>
</section>

<section class="grid gap-6 lg:grid-cols-2 mt-8">
  <div class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold mb-3">Últimos pagos</h2>
    <?php if (!$recentPayments): ?>
      <p class="text-sm text-slate-500">Todavía no hay pagos.</p>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs uppercase text-slate-500">
          <th class="pb-2">Usuario</th><th class="pb-2">Monto</th><th class="pb-2">Estado</th><th class="pb-2">Fecha</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentPayments as $p):
            $color = match ($p['status']) {
                'APPROVED' => 'text-emerald-600',
                'PENDING'  => 'text-amber-600',
                default    => 'text-rose-600',
            }; ?>
          <tr class="border-t border-slate-100">
            <td class="py-2 truncate max-w-[12rem]" title="<?= e($p['reference']) ?>"><?= e($p['email']) ?></td>
            <td class="py-2"><?= e($money((int) $p['amount_in_cents'])) ?>
              <?php if ($p['promo_code']): ?><span class="text-xs text-slate-400"><?= e($p['promo_code']) ?></span><?php endif; ?>
            </td>
            <td class="py-2 font-semibold <?= $color ?>"><?= e($p['status']) ?></td>
            <td class="py-2 text-slate-500"><?= e($p['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold mb-3">Plantillas más usadas</h2>
    <ul class="text-sm divide-y divide-slate-100">
      <?php foreach ($topTemplates as $t): ?>
        <li class="py-2 flex justify-between">
          <span><?= e($t['name']) ?>
            <span class="text-xs <?= $t['is_premium'] ? 'text-amber-600' : 'text-emerald-600' ?>"><?= $t['is_premium'] ? 'Premium' : 'Gratis' ?></span>
          </span>
          <span class="font-semibold"><?= (int) $t['total'] ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <a href="<?= e(url('admin/templates.php')) ?>" class="inline-block mt-4 <?= ADMIN_BTN_CLS ?>">Gestionar plantillas</a>
  </div>
</section>

<section class="<?= ADMIN_CARD_CLS ?> mt-6">
  <h2 class="font-semibold mb-3">Actividad administrativa reciente</h2>
  <?php if (!$audit): ?>
    <p class="text-sm text-slate-500">Sin actividad registrada.</p>
  <?php else: ?>
    <ul class="text-sm divide-y divide-slate-100">
      <?php foreach ($audit as $a): ?>
        <li class="py-2 flex gap-3">
          <span class="text-slate-500 shrink-0"><?= e($a['created_at']) ?></span>
          <span class="font-semibold shrink-0"><?= e($a['action']) ?></span>
          <span class="text-slate-600 truncate"><?= e($a['detail']) ?></span>
          <span class="ml-auto text-slate-400 shrink-0"><?= e($a['email'] ?? '—') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php admin_page_end();
