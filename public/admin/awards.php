<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Premios del top de donadores: clasificación real (con correo, solo admin), meses cerrados, concesiones y configuración. */
$admin = Admin::guard();
$pdo = db();

/** '12.50' -> 1250; null si no es un monto válido (máx. 5 enteros y 2 decimales). */
$usdToCents = static function (string $s): ?int {
    $s = trim($s);
    return preg_match('/^\d{1,5}(\.\d{1,2})?$/', $s) ? (int) round((float) $s * 100) : null;
};

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'close_months') {
        $r = Awards::closePendingMonths($pdo);
        Admin::log('awards_close', 'meses: ' . ($r['closed'] ? implode(',', $r['closed']) : 'ninguno') . '; premios: ' . $r['granted']);
        flash($r['closed'] ? 'Meses cerrados: ' . implode(', ', $r['closed']) . ' (' . $r['granted'] . ' premios).' : 'No había meses pendientes.');
        redirect('admin/awards.php');
    } elseif ($action === 'save_config') {
        $coins = [];
        foreach ((array) ($_POST['place_coins'] ?? []) as $c) {
            $coins[] = is_string($c) ? trim($c) : '';
        }
        $ms = [];
        $mUsd = (array) ($_POST['ms_usd'] ?? []);
        $mCoins = (array) ($_POST['ms_coins'] ?? []);
        foreach ($mUsd as $i => $u) {
            $u = is_string($u) ? trim($u) : '';
            $c = is_string($mCoins[$i] ?? null) ? trim($mCoins[$i]) : '';
            if ($u === '' && $c === '') {
                continue;
            }
            $ms[] = ['cents' => $usdToCents($u), 'coins' => $c];
        }
        $errors = Awards::saveConfig([
            'top_n'           => is_string($_POST['top_n'] ?? null) ? trim($_POST['top_n']) : '',
            'month_coins'     => $coins,
            'min_month_cents' => $usdToCents((string) ($_POST['min_usd'] ?? '')),
            'milestones'      => $ms,
        ]);
        if ($errors === []) {
            Admin::log('awards_config', (string) json_encode(Awards::config()));
            flash('Configuración de premios guardada.');
            redirect('admin/awards.php');
        }
    } elseif ($action === 'clear_alias') {
        $target = (int) ($_POST['user_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 200) {
            $errors[] = 'La nota es obligatoria (máximo 200 caracteres).';
        } elseif ($target > 0 && Ranking::clearAlias($target)) {
            Admin::log('awards_alias_clear', 'usuario #' . $target . ': ' . $note);
            flash('Alias eliminado; el usuario aparece como anónimo.');
            redirect('admin/awards.php');
        } else {
            $errors[] = 'Ese usuario no tiene alias que quitar.';
        }
    }
}

$cfg = Awards::config();
$launch = Awards::launchMonth();
$ymNow = Awards::monthKey();
$pending = Awards::pendingMonths($pdo);
[$from, $to] = Access::monthBounds();
$rows = Ranking::rows($from, $to, 20, true);
$closed = $pdo->query('SELECT c.ym, c.closed_at, (SELECT COUNT(*) FROM award_grants g WHERE g.kind = \'month\' AND g.grant_key LIKE CONCAT(\'month:\', c.ym, \':%\')) AS n
                         FROM awards_closed_months c ORDER BY c.ym DESC LIMIT 12')->fetchAll();
$grants = $pdo->query('SELECT g.grant_key, g.kind, g.coins, g.created_at, u.email FROM award_grants g JOIN users u ON u.id = g.user_id ORDER BY g.id DESC LIMIT 30')->fetchAll();
$totalGrants = (int) $pdo->query('SELECT COUNT(*) FROM award_grants')->fetchColumn();
$coinsGiven = (int) $pdo->query('SELECT COALESCE(SUM(coins), 0) FROM award_grants')->fetchColumn();

$val = static fn(string $k, string $default): string => $errors !== [] && isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : $default;
$msRows = $cfg['milestones'];
while (count($msRows) < Awards::MAX_MILESTONES) {
    $msRows[] = ['cents' => null, 'coins' => null];
}

admin_page_start('Premios del top', 'awards', 'Top de donadores: premios automáticos e insignias.');
admin_errors($errors);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?= admin_stat('Mes en curso', $ymNow, 'Lanzamiento: ' . $launch, 'calendar', 'rose') ?>
  <?= admin_stat('Meses pendientes', (string) count($pending), $pending ? implode(', ', $pending) : 'Todo al día', 'clock', $pending ? 'amber' : 'green') ?>
  <?= admin_stat('Premios otorgados', (string) $totalGrants, '', 'check', 'green') ?>
  <?= admin_stat('Monedas regaladas', (string) $coinsGiven, '', 'coins', 'amber') ?>
</div>

<section class="<?= ADMIN_CARD_CLS ?> mb-6">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <h2 class="font-semibold text-slate-900">Clasificación de <?= e($ymNow) ?> (datos reales, solo admin)</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close_months">
      <button class="<?= ADMIN_BTN_CLS ?>"<?= $pending ? '' : ' disabled aria-disabled="true"' ?>>Cerrar meses pendientes</button></form>
  </div>
  <?php if (!$rows): ?>
    <?= admin_empty('Sin apoyo este mes', 'Solo cuentan pagos WOMPI/MANUAL aprobados y cumplidos.') ?>
  <?php else: ?>
    <?= admin_table_open(['#', 'Correo', 'Alias público', 'Total' => 'right', 'Quitar alias'], ['caption' => 'Clasificación del mes']) ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td class="px-4 py-3 tabular"><?= $i + 1 ?></td>
        <td class="px-4 py-3 break-all"><?= e($r['email'] ?? '') ?></td>
        <td class="px-4 py-3"><?= $r['display_name'] !== null ? e($r['display_name']) . ' ' . ($r['show'] === 1 ? admin_badge('visible', 'green') : admin_badge('oculto', 'slate')) : '<span class="text-slate-400">—</span>' ?></td>
        <td class="px-4 py-3 text-right tabular"><?= e(admin_money($r['total'])) ?></td>
        <td class="px-4 py-3">
          <?php if ($r['display_name'] !== null || $r['show'] === 1): ?>
            <form method="post" class="flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="clear_alias"><input type="hidden" name="user_id" value="<?= $r['user_id'] ?>">
              <input name="note" required maxlength="200" placeholder="Motivo" aria-label="Motivo para quitar el alias" class="<?= ADMIN_INPUT_CLS ?> min-w-[8rem]">
              <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Quitar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?= admin_table_close() ?>
  <?php endif; ?>
</section>

<section class="<?= ADMIN_CARD_CLS ?> mb-6">
  <h2 class="font-semibold text-slate-900 mb-3">Configuración</h2>
  <form method="post" class="space-y-4"><?= csrf_field() ?><input type="hidden" name="action" value="save_config">
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-semibold mb-1" for="top_n">Tamaño del top mensual premiado (1–<?= Awards::MAX_TOP_N ?>)</label>
        <input id="top_n" name="top_n" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($val('top_n', (string) $cfg['top_n'])) ?>"></div>
      <div><label class="block text-sm font-semibold mb-1" for="min_usd">Gasto mínimo del mes (USD)</label>
        <input id="min_usd" name="min_usd" inputmode="decimal" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($val('min_usd', number_format($cfg['min_month_cents'] / 100, 2, '.', ''))) ?>"></div>
    </div>
    <fieldset><legend class="text-sm font-semibold mb-1">Monedas por puesto (se usan los primeros N; máx. <?= Awards::MAX_COINS ?>)</legend>
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
        <?php for ($i = 0; $i < Awards::MAX_TOP_N; $i++): $pc = (array) ($_POST['place_coins'] ?? []); ?>
          <label class="text-xs text-slate-500"><?= $i + 1 ?>.º<input name="place_coins[]" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($errors !== [] && is_string($pc[$i] ?? null) ? $pc[$i] : (string) ($cfg['month_coins'][$i] ?? '')) ?>"></label>
        <?php endfor; ?>
      </div></fieldset>
    <fieldset><legend class="text-sm font-semibold mb-1">Hitos de gasto acumulado (crecientes; deja vacío lo que no uses)</legend>
      <div class="space-y-2">
        <?php foreach ($msRows as $i => $m): $mu = (array) ($_POST['ms_usd'] ?? []); $mc = (array) ($_POST['ms_coins'] ?? []); ?>
          <div class="grid grid-cols-2 gap-2">
            <label class="text-xs text-slate-500">Hito <?= $i + 1 ?> (USD)<input name="ms_usd[]" inputmode="decimal" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($errors !== [] && is_string($mu[$i] ?? null) ? $mu[$i] : ($m['cents'] !== null ? number_format($m['cents'] / 100, 2, '.', '') : '')) ?>"></label>
            <label class="text-xs text-slate-500">Monedas<input name="ms_coins[]" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($errors !== [] && is_string($mc[$i] ?? null) ? $mc[$i] : (string) ($m['coins'] ?? '')) ?>"></label>
          </div>
        <?php endforeach; ?>
      </div></fieldset>
    <button class="<?= ADMIN_BTN_CLS ?>">Guardar configuración</button>
  </form>
</section>

<div class="grid lg:grid-cols-2 gap-6">
  <section class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold text-slate-900 mb-3">Meses cerrados</h2>
    <?php if (!$closed): ?><?= admin_empty('Aún no se cierra ningún mes') ?><?php else: ?>
      <?= admin_table_open(['Mes', 'Cerrado', 'Premios' => 'right'], ['caption' => 'Meses cerrados']) ?>
      <?php foreach ($closed as $c): ?><tr><td class="px-4 py-3"><?= e((string) $c['ym']) ?></td><td class="px-4 py-3"><?= e(admin_date((string) $c['closed_at'])) ?></td><td class="px-4 py-3 text-right tabular"><?= (int) $c['n'] ?></td></tr><?php endforeach; ?>
      <?= admin_table_close() ?>
    <?php endif; ?>
  </section>
  <section class="<?= ADMIN_CARD_CLS ?>">
    <h2 class="font-semibold text-slate-900 mb-3">Concesiones recientes</h2>
    <?php if (!$grants): ?><?= admin_empty('Sin concesiones todavía') ?><?php else: ?>
      <?= admin_table_open(['Fecha', 'Usuario', 'Premio', 'Monedas' => 'right'], ['caption' => 'Concesiones recientes']) ?>
      <?php foreach ($grants as $g): ?><tr><td class="px-4 py-3 tabular"><?= e(admin_date((string) $g['created_at'])) ?></td><td class="px-4 py-3 break-all"><?= e((string) $g['email']) ?></td><td class="px-4 py-3"><?= admin_badge((string) $g['grant_key'], $g['kind'] === 'month' ? 'amber' : 'violet') ?></td><td class="px-4 py-3 text-right tabular"><?= (int) $g['coins'] ?></td></tr><?php endforeach; ?>
      <?= admin_table_close() ?>
    <?php endif; ?>
  </section>
</div>
<?php admin_page_end();
