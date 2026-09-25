<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Programa de creadores: cola de revisión de plantillas públicas, ganancias y configuración (todo con CSRF y auditoría). */
$admin = Admin::guard();
$pdo = db();
CreatorEarnings::settleAll();   // perezoso: liquida ganancias pendientes que hubieran quedado sin pagar

$tabs = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'withdrawn' => 'Retiradas'];
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'pending';
$errors = [];
$str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_verify();
    $action = $str('action');
    $tid = (int) ($_POST['id'] ?? 0);
    if ($action === 'approve') {
        $adj = ['category' => $str('category'), 'quota' => !empty($_POST['quota'])];
        if (preg_match('/^\d{1,5}$/', $str('price')) === 1) {
            $adj['price'] = (int) $str('price');
        } else {
            $errors[] = 'Precio inválido.';
        }
        $errors = $errors ?: Creators::approve((int) $admin['id'], $tid, $adj);
        if (!$errors) {
            Admin::log('creator_approve', 'plantilla #' . $tid);
            flash('Plantilla aprobada y publicada en la Galería.');
            redirect('admin/creators.php');
        }
    } elseif ($action === 'reject') {
        $errors = Creators::reject((int) $admin['id'], $tid, $str('note'));
        if (!$errors) {
            Admin::log('creator_reject', 'plantilla #' . $tid . ': ' . mb_substr($str('note'), 0, 120));
            flash('Plantilla rechazada; el autor verá la nota.');
            redirect('admin/creators.php');
        }
    } elseif ($action === 'withdraw') {
        if (Creators::withdraw($tid, null, (int) $admin['id'])) {
            Admin::log('creator_withdraw', 'plantilla #' . $tid);
            flash('Plantilla retirada del catálogo. Las páginas ya creadas siguen activas.');
            redirect('admin/creators.php?tab=approved');
        }
        $errors[] = 'No se pudo retirar (¿ya no está aprobada?).';
    } elseif ($action === 'save_config') {
        $ms = [];
        foreach ((array) ($_POST['ms_count'] ?? []) as $i => $c) {
            $c = is_string($c) ? trim($c) : '';
            $coins = is_string(((array) ($_POST['ms_coins'] ?? []))[$i] ?? null) ? trim(((array) $_POST['ms_coins'])[$i]) : '';
            $badge = is_string(((array) ($_POST['ms_badge'] ?? []))[$i] ?? null) ? ((array) $_POST['ms_badge'])[$i] : '';
            if ($c === '' && $coins === '') {
                continue;
            }
            $ms[] = ['count' => $c, 'coins' => $coins, 'badge' => $badge];
        }
        $errors = Creators::saveConfig([
            'share_pct' => $str('share_pct'), 'share_on_quota_unlock' => !empty($_POST['share_on_quota_unlock']),
            'min_price' => $str('min_price'), 'max_price' => $str('max_price'), 'max_pending' => $str('max_pending'),
            'max_templates' => $str('max_templates'), 'success_uses' => $str('success_uses'), 'milestones' => $ms,
            'month_min_templates' => $str('month_min_templates'), 'month_min_uses' => $str('month_min_uses'), 'month_coins' => $str('month_coins'),
            'top_tier_slug' => $str('top_tier_slug'), 'top_tier_days' => $str('top_tier_days'),
        ]);
        if (!$errors) {
            Admin::log('creators_config', (string) json_encode(Creators::config()));
            flash('Configuración de creadores guardada.');
            redirect('admin/creators.php?tab=' . $tab . '#config');
        }
    } else {
        $errors[] = 'Acción desconocida.';
    }
}

$cfg = Creators::config();
$rows = Creators::queue($tab);
$counts = [];
foreach (array_keys($tabs) as $s) {
    $counts[$s] = (int) $pdo->query("SELECT COUNT(*) FROM templates WHERE kind = 'utpl' AND review_status = " . $pdo->quote($s))->fetchColumn();
}
$earn = $pdo->query("SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN share_coins END), 0) AS p, COALESCE(SUM(CASE WHEN status = 'paid' THEN share_coins END), 0) AS d FROM template_earnings")->fetch();
$tiers = Access::tiers();
$msRows = $cfg['milestones'];
while (count($msRows) < Awards::MAX_MILESTONES) {
    $msRows[] = ['count' => '', 'coins' => '', 'badge' => ''];
}
$posted = static fn (string $k, string $default): string => $errors !== [] && is_string($_POST[$k] ?? null) ? (string) $_POST[$k] : $default;
$lab = 'block text-sm font-semibold mb-1';

admin_page_start('Creadores', 'creators', 'Plantillas públicas de usuarios: revisión, reparto de ingresos y premios.');
admin_errors($errors);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?= admin_stat('Pendientes', (string) $counts['pending'], 'por revisar', 'clock', $counts['pending'] > 0 ? 'amber' : 'green') ?>
  <?= admin_stat('Aprobadas', (string) $counts['approved'], 'en la Galería', 'check', 'green') ?>
  <?= admin_stat('Ganancias pendientes', (string) (int) $earn['p'], 'monedas', 'coins', 'amber') ?>
  <?= admin_stat('Ganancias pagadas', (string) (int) $earn['d'], 'monedas', 'coins', 'rose') ?>
</div>

<nav class="flex flex-wrap gap-2 mb-4" aria-label="Estado">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(url('admin/creators.php?tab=' . $k)) ?>" class="<?= $tab === $k ? ADMIN_BTN_CLS : ADMIN_BTN_GHOST_CLS ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$k] ?>)</a>
  <?php endforeach; ?>
</nav>

<section class="space-y-4 mb-8" aria-label="<?= e($tabs[$tab]) ?>">
  <?php if (!$rows): ?><?= admin_empty('Sin plantillas en esta pestaña') ?><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <article class="<?= ADMIN_CARD_CLS ?>">
      <div class="grid lg:grid-cols-2 gap-5">
        <div>
          <div class="flex items-start gap-3">
            <?php if ($r['thumbnail']): ?><img src="<?= e(url('assets/thumbs/' . $r['thumbnail'])) ?>" alt="" width="120" height="72" class="rounded-lg border border-slate-200 shrink-0"><?php endif; ?>
            <div class="min-w-0">
              <h2 class="font-semibold text-slate-900 break-words"><?= e((string) $r['name']) ?></h2>
              <p class="text-xs text-slate-500"><?= e(Template::CATEGORIES[$r['category']] ?? '') ?> · <?= (int) $r['price_coins'] ?> monedas · <?= (int) $r['membership_unlocks'] === 1 ? 'con cupo de membresía' : 'solo monedas' ?> · <?= (int) $r['uses'] ?> usos</p>
              <p class="text-xs text-slate-500">Autor: <?= e((string) ($r['owner_alias'] ?? '—')) ?> · <span class="break-all"><?= e((string) ($r['owner_email'] ?? 'sin cuenta')) ?></span></p>
              <p class="text-xs text-slate-500">Enviada: <?= e(admin_date((string) $r['submitted_at'])) ?><?= $r['credit_alias'] ? ' · Crédito: ' . e((string) $r['credit_alias']) : '' ?></p>
            </div>
          </div>
          <?php if ($r['description']): ?><p class="mt-2 text-sm text-slate-700 break-words"><?= e((string) $r['description']) ?></p><?php endif; ?>
          <?php if ($r['review_note']): ?><p class="mt-2 text-sm text-rose-800">Nota: <?= e((string) $r['review_note']) ?></p><?php endif; ?>
          <?php if ($r['image_spec']): ?><p class="mt-1 text-xs text-slate-500">Pide fotos (hasta <?= (int) (json_decode((string) $r['image_spec'], true)['repeat']['max'] ?? 0) ?>).</p><?php endif; ?>

          <?php if ($tab === 'pending'): ?>
            <form method="post" class="mt-4 grid grid-cols-2 gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <label class="text-sm font-semibold">Precio (monedas)<input name="price" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= (int) $r['price_coins'] ?>"></label>
              <label class="text-sm font-semibold">Categoría<select name="category" class="<?= ADMIN_INPUT_CLS ?>"><?php foreach (Template::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $r['category'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
              <label class="col-span-2 text-sm flex items-center gap-2"><input type="checkbox" name="quota" value="1"<?= (int) $r['membership_unlocks'] === 1 ? ' checked' : '' ?>> Incluir en el cupo mensual de membresías</label>
              <div class="col-span-2"><button class="<?= ADMIN_BTN_CLS ?>">Aprobar y publicar</button></div>
            </form>
            <form method="post" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <label class="text-sm font-semibold" for="note-<?= (int) $r['id'] ?>">Nota de rechazo (obligatoria, la verá el autor)</label>
              <input id="note-<?= (int) $r['id'] ?>" name="note" maxlength="255" required class="<?= ADMIN_INPUT_CLS ?>">
              <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Rechazar</button>
            </form>
          <?php elseif ($tab === 'approved'): ?>
            <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Retirar del catálogo</button></form>
          <?php endif; ?>
        </div>
        <div>
          <p class="text-xs font-semibold text-slate-500 mb-1">Vista previa aislada (datos de ejemplo)</p>
          <iframe src="<?= e(url('admin/creator_preview.php?id=' . (int) $r['id'])) ?>" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy"
                  title="Vista previa de <?= e((string) $r['name']) ?>" class="w-full h-80 rounded-lg border border-slate-200 bg-white"></iframe>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<section id="config" class="<?= ADMIN_CARD_CLS ?>">
  <h2 class="font-semibold text-slate-900 mb-3">Configuración de creadores</h2>
  <form method="post" class="space-y-4"><?= csrf_field() ?><input type="hidden" name="action" value="save_config">
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="<?= $lab ?>" for="share_pct">% para el creador (0–90)</label><input id="share_pct" name="share_pct" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('share_pct', (string) $cfg['share_pct'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="min_price">Precio mínimo (monedas)</label><input id="min_price" name="min_price" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('min_price', (string) $cfg['min_price'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="max_price">Precio máximo (monedas)</label><input id="max_price" name="max_price" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('max_price', (string) $cfg['max_price'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="max_pending">Máx. envíos pendientes por usuario</label><input id="max_pending" name="max_pending" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('max_pending', (string) $cfg['max_pending'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="max_templates">Máx. plantillas públicas por usuario</label><input id="max_templates" name="max_templates" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('max_templates', (string) $cfg['max_templates'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="success_uses">Usos (de otros) para ser «exitosa»</label><input id="success_uses" name="success_uses" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('success_uses', (string) $cfg['success_uses'])) ?>"></div>
    </div>
    <label class="text-sm flex items-center gap-2"><input type="checkbox" name="share_on_quota_unlock" value="1"<?= ($errors !== [] ? !empty($_POST['share_on_quota_unlock']) : $cfg['share_on_quota_unlock']) ? ' checked' : '' ?>> Pagar también cuando se usa por cupo de membresía (sobre el precio nominal, lo financia la plataforma)</label>
    <fieldset><legend class="text-sm font-semibold mb-1">Hitos por plantillas aprobadas (crecientes; vacío = sin usar)</legend>
      <div class="space-y-2">
        <?php foreach ($msRows as $i => $m): $pc = (array) ($_POST['ms_count'] ?? []); $pm = (array) ($_POST['ms_coins'] ?? []); $pb = (array) ($_POST['ms_badge'] ?? []); ?>
          <div class="grid grid-cols-3 gap-2">
            <label class="text-xs text-slate-500">Plantillas<input name="ms_count[]" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($errors !== [] && is_string($pc[$i] ?? null) ? $pc[$i] : (string) $m['count']) ?>"></label>
            <label class="text-xs text-slate-500">Monedas<input name="ms_coins[]" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($errors !== [] && is_string($pm[$i] ?? null) ? $pm[$i] : (string) $m['coins']) ?>"></label>
            <label class="text-xs text-slate-500">Insignia<select name="ms_badge[]" class="<?= ADMIN_INPUT_CLS ?>"><?php foreach (Creators::BADGE_CHOICES as $b): $cur = $errors !== [] && is_string($pb[$i] ?? null) ? $pb[$i] : (string) $m['badge']; ?><option value="<?= e($b) ?>"<?= $cur === $b ? ' selected' : '' ?>><?= e($b === '' ? 'Ninguna' : Awards::BADGES[$b][0]) ?></option><?php endforeach; ?></select></label>
          </div>
        <?php endforeach; ?>
      </div></fieldset>
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="<?= $lab ?>" for="month_min_templates">Premio mensual: plantillas mínimas</label><input id="month_min_templates" name="month_min_templates" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('month_min_templates', (string) $cfg['month_min_templates'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="month_min_uses">Premio mensual: usos mínimos en el mes</label><input id="month_min_uses" name="month_min_uses" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('month_min_uses', (string) $cfg['month_min_uses'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="month_coins">Premio mensual: monedas</label><input id="month_coins" name="month_coins" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('month_coins', (string) $cfg['month_coins'])) ?>"></div>
      <div><label class="<?= $lab ?>" for="top_tier_slug">N.º 1 del mes: plan temporal</label>
        <select id="top_tier_slug" name="top_tier_slug" class="<?= ADMIN_INPUT_CLS ?>"><option value="">Ninguno</option><?php foreach ($tiers as $t): ?><option value="<?= e((string) $t['slug']) ?>"<?= $posted('top_tier_slug', (string) $cfg['top_tier_slug']) === $t['slug'] ? ' selected' : '' ?>><?= e((string) $t['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $lab ?>" for="top_tier_days">Días de la mejora temporal (0 = desactivada)</label><input id="top_tier_days" name="top_tier_days" inputmode="numeric" class="<?= ADMIN_INPUT_CLS ?>" value="<?= e($posted('top_tier_days', (string) $cfg['top_tier_days'])) ?>"></div>
    </div>
    <p class="text-xs text-slate-500">Los cambios rigen hacia adelante: no afectan a ganancias ni premios ya generados. Lanzamiento de premios: <?= e(Creators::launchMonth()) ?>.</p>
    <button class="<?= ADMIN_BTN_CLS ?>">Guardar configuración</button>
  </form>
</section>
<?php admin_page_end();
