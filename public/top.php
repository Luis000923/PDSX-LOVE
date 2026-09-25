<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Top de donadores (mes e histórico). Público; NUNCA muestra correo ni datos reales: solo el alias de quien
 * lo activó (opt-in) o «Donador anónimo». Los meses terminados se cierran aquí de forma perezosa (sin cron) y,
 * para quien ya gastó antes del lanzamiento, los hitos se otorgan al entrar (también de forma perezosa).
 */
$user = current_user();
$uid  = $user !== null ? (int) $user['id'] : 0;
$view = in_array($_GET['vista'] ?? 'mes', Ranking::VIEWS, true) ? (string) ($_GET['vista'] ?? 'mes') : 'mes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_verify();
    $user = require_login();
    $r = Ranking::saveOptIn((int) $user['id'], !empty($_POST['show_in_rankings']), (string) ($_POST['display_name'] ?? ''));
    flash($r['ok'] ? ($r['charged'] > 0 ? 'Alias actualizado. Se descontaron ' . $r['charged'] . ' monedas.' : (!empty($_POST['show_in_rankings']) ? 'Listo: tu alias aparecerá en el top.' : 'Listo: ahora apareces como «Donador anónimo».')) : (string) $r['error']);
    redirect('top.php?vista=' . $view . '#privacidad');
}

try {
    Awards::closePendingMonths();
    if ($uid > 0) {
        Awards::evaluateMilestones(db(), $uid);
    }
} catch (Throwable $e) {
    error_log('top.php premios: ' . $e->getMessage());   // los premios nunca tumban la página
}

header('Cache-Control: private, no-cache');
$cfg     = Awards::config();
$usd     = static fn(int $c): string => '$' . number_format($c / 100, 2, '.', ',');
$meses   = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
[$mFrom, $mTo] = Access::monthBounds();
$ymNow   = Awards::monthKey();
$mesName = $meses[(int) substr($ymNow, 5, 2) - 1] . ' de ' . substr($ymNow, 0, 4);
$top     = Ranking::publicTop($view, 10);
$mine    = $uid > 0 ? ['mes' => Ranking::standing($uid, 'mes'), 'historico' => Ranking::standing($uid, 'historico')] : [];
$opt     = $uid > 0 ? Ranking::optIn($uid) : ['alias' => '', 'show' => false];
$life    = $mine['historico']['cents'] ?? 0;
$prog    = Awards::progress($life);
$done    = $uid > 0 ? Awards::grantedMilestones($uid) : [];
$myBadges = $uid > 0 ? Awards::userBadges($uid) : [];
$medal   = [1 => 'mecenas-1', 2 => 'mecenas-2', 3 => 'mecenas-3'];
$ico     = static fn(string $n, int $s = 24): string => '<img src="' . e(url('assets/img/awards/' . $n . '.svg')) . '" alt="" width="' . $s . '" height="' . $s . '" class="shrink-0">';
$tab     = fn(string $v, string $label) => '<a href="' . e(url('top.php?vista=' . $v)) . '"' . ($view === $v ? ' aria-current="page"' : '')
    . ' class="flex-1 text-center rounded-full px-4 min-h-[44px] flex items-center justify-center text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 '
    . ($view === $v ? 'bg-rose-600 text-white' : 'text-rose-700 hover:bg-rose-50') . '">' . e($label) . '</a>';
$card    = 'rounded-2xl bg-white border border-rose-100 p-5';

page_start('Top de donadores', 'max-w-3xl');
?>
<section class="mt-2 flex items-center gap-4">
  <?= $ico('trofeo', 56) ?>
  <div class="min-w-0">
    <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Top de donadores</h1>
    <p class="mt-1 text-sm text-slate-600">Gracias a quienes apoyan LovePages. Cada mes premiamos a los primeros con monedas e insignias.</p>
  </div>
</section>

<nav class="mt-5" aria-label="Periodo del top">
  <div class="flex gap-1 rounded-full bg-white border border-rose-100 p-1"><?= $tab('mes', 'Este mes') ?><?= $tab('historico', 'Histórico') ?></div>
</nav>

<section class="<?= $card ?> mt-4" aria-labelledby="h-top">
  <h2 id="h-top" class="font-semibold"><?= $view === 'mes' ? 'Top de ' . e($mesName) : 'Top histórico' ?></h2>
  <?php if ($top === []): ?>
    <div class="text-center py-8">
      <?= $ico('trofeo', 64) ?>
      <p class="mt-3 font-semibold">Aún no hay donadores <?= $view === 'mes' ? 'este mes' : '' ?></p>
      <p class="text-sm text-slate-600">Sé la primera persona en apoyar con una membresía o una recarga de monedas.</p>
      <a class="mt-3 inline-flex items-center min-h-[44px] font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('tienda.php')) ?>">Ir a la tienda</a>
    </div>
  <?php else: ?>
    <ol class="mt-3 divide-y divide-rose-50">
      <?php foreach ($top as $r): ?>
        <li class="py-3 flex items-center gap-3">
          <span class="w-9 shrink-0 flex justify-center">
            <?php if (isset($medal[$r['pos']])): ?><?= $ico($medal[$r['pos']], 32) ?><span class="sr-only">Puesto <?= $r['pos'] ?></span>
            <?php else: ?><span class="text-sm font-bold text-slate-500">#<?= $r['pos'] ?></span><?php endif; ?>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold <?= $r['name'] === null ? 'text-slate-500' : '' ?>"><?= e($r['name'] ?? Ranking::ANON) ?></span>
            <?php if ($r['badges']): ?><span class="mt-1 flex gap-1"><?php foreach ($r['badges'] as $b): ?><?= Awards::badgeImg($b, 20) ?><?php endforeach; ?></span><?php endif; ?>
          </span>
          <span class="shrink-0 font-bold text-rose-700 tabular-nums"><?= e($usd($r['cents'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<?php if ($uid > 0): ?>
<section class="<?= $card ?> mt-4" aria-labelledby="h-mine">
  <h2 id="h-mine" class="font-semibold">Tu posición</h2>
  <dl class="mt-2 grid grid-cols-2 gap-3 text-sm">
    <?php foreach (['mes' => 'Este mes', 'historico' => 'Histórico'] as $k => $lbl): ?>
      <div class="rounded-xl bg-rose-50 px-3 py-2">
        <dt class="text-slate-600"><?= e($lbl) ?></dt>
        <dd class="font-bold"><?= $mine[$k] !== null ? '#' . $mine[$k]['pos'] . ' · ' . e($usd($mine[$k]['cents'])) : 'Sin apoyo aún' ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
  <?php if ($myBadges): ?>
    <p class="mt-3 text-sm text-slate-600">Tus insignias</p>
    <ul class="mt-1 flex flex-wrap gap-2">
      <?php foreach ($myBadges as $b): ?><li class="inline-flex items-center gap-1 rounded-full bg-white border border-rose-100 pl-1 pr-3 py-1 text-xs font-semibold"><?= Awards::badgeImg($b['key'], 24) ?><?= e(Awards::BADGES[$b['key']][0]) ?><?= $b['period'] !== '' ? ' · ' . e($b['period']) : '' ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="<?= $card ?> mt-4" aria-labelledby="h-prem">
  <h2 id="h-prem" class="font-semibold">Premios de este mes</h2>
  <p class="text-sm text-slate-600 mt-1">Se reinicia el <?= e(Access::monthResetLabel($mTo)) ?>. Para entrar al podio necesitas apoyar con al menos <?= e($usd((int) $cfg['min_month_cents'])) ?> en el mes.</p>
  <ul class="mt-3 space-y-2 text-sm">
    <?php foreach ($cfg['month_coins'] as $i => $coins): $p = $i + 1; ?>
      <li class="flex items-center gap-3"><?= $ico($medal[$p] ?? 'medalla', 28) ?>
        <span class="flex-1"><?= $p ?>.º puesto<span class="text-slate-500"> · <?= e(Awards::BADGES[$p <= 3 ? 'mecenas_' . $p : 'top_mes'][0]) ?></span></span>
        <strong><?= (int) $coins ?> monedas</strong></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="<?= $card ?> mt-4" aria-labelledby="h-hitos">
  <h2 id="h-hitos" class="font-semibold">Hitos de apoyo</h2>
  <p class="text-sm text-slate-600 mt-1">Con tu apoyo acumulado desbloqueas monedas e insignias, una sola vez cada una.</p>
  <?php if ($uid > 0): ?>
    <div class="mt-3" role="group" aria-label="Progreso hacia el siguiente hito">
      <?php if ($prog['next'] !== null): ?>
        <p class="text-sm">Llevas <strong><?= e($usd($life)) ?></strong>. Te faltan <strong><?= e($usd($prog['left'])) ?></strong> para el hito de <?= e($usd($prog['next']['cents'])) ?> (+<?= (int) $prog['next']['coins'] ?> monedas).</p>
        <div class="mt-2 h-2.5 rounded-full bg-rose-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $prog['pct'] ?>"><div class="h-2.5 rounded-full bg-rose-500" style="width: <?= $prog['pct'] ?>%"></div></div>
      <?php else: ?>
        <p class="text-sm">Llevas <strong><?= e($usd($life)) ?></strong>: ¡completaste todos los hitos! Gracias.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <ul class="mt-3 space-y-2 text-sm">
    <?php foreach ($cfg['milestones'] as $i => $m): $got = in_array($m['cents'], $done, true); ?>
      <li class="flex items-center gap-3 <?= $got ? '' : 'opacity-70' ?>"><?= $ico(Awards::BADGES[Awards::milestoneBadge($i)][2], 28) ?>
        <span class="flex-1"><?= e($usd($m['cents'])) ?><span class="text-slate-500"> · <?= e(Awards::BADGES[Awards::milestoneBadge($i)][0]) ?></span></span>
        <strong><?= (int) $m['coins'] ?> monedas</strong><?= $got ? '<span class="sr-only"> (conseguido)</span><span aria-hidden="true">✓</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<section id="privacidad" class="<?= $card ?> mt-4 mb-4 scroll-mt-4" aria-labelledby="h-priv">
  <h2 id="h-priv" class="font-semibold">Tu privacidad</h2>
  <p class="text-sm text-slate-600 mt-1">Nunca mostramos tu correo ni tus datos. Por defecto apareces como «<?= e(Ranking::ANON) ?>» con tu posición y total. Si lo activas, se mostrará públicamente solo el alias que elijas. Puedes desactivarlo cuando quieras.</p>
  <?php if ($uid > 0): ?>
    <form method="post" class="mt-3 space-y-3">
      <?= csrf_field() ?>
      <div>
        <label for="display_name" class="block text-sm font-semibold mb-1">Alias público</label>
        <input id="display_name" name="display_name" class="<?= INPUT_CLS ?> min-h-[44px]" maxlength="<?= Ranking::ALIAS_MAX ?>" autocomplete="off" aria-describedby="alias-help" value="<?= e($opt['alias']) ?>">
        <p id="alias-help" class="mt-1 text-xs text-slate-600"><?= Ranking::ALIAS_MIN ?>–<?= Ranking::ALIAS_MAX ?> caracteres: letras, números, espacios, . _ -. Sin enlaces ni @.<?= $opt['alias'] !== '' && Ranking::aliasChangeCost() > 0 ? ' <strong>Cambiar tu alias cuesta ' . Ranking::aliasChangeCost() . ' monedas</strong>; ocultarlo es gratis.' : '' ?></p>
      </div>
      <label class="flex items-start gap-3 min-h-[44px] text-sm"><input type="checkbox" name="show_in_rankings" value="1" class="mt-1 h-5 w-5 accent-rose-600" <?= $opt['show'] ? 'checked' : '' ?>>
        <span>Mostrar mi alias en el top de donadores</span></label>
      <button class="<?= BTN_CLS ?> min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2">Guardar</button>
    </form>
  <?php else: ?>
    <p class="mt-3 text-sm"><a class="font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url('login.php')) ?>">Entra</a> para ver tu posición, tus hitos y elegir tu alias.</p>
  <?php endif; ?>
</section>
<?php page_end();
