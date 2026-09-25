<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Colaboradores destacados (público): autores con plantillas exitosas. Solo alias de autoría, nunca correos. */
try {
    Awards::closePendingMonths();   // cierre perezoso de meses (donadores y creadores)
} catch (Throwable $e) {
    error_log('colaboradores premios: ' . $e->getMessage());
}
$cfg = Creators::config();
$rows = Creators::collaborators(20);
$user = current_user();
$card = 'rounded-2xl bg-white border border-rose-100 p-5';
page_start('Colaboradores destacados', 'max-w-3xl');
?>
<section class="mt-2 mb-4">
  <h1 class="text-2xl font-bold">Colaboradores destacados</h1>
  <p class="mt-1 text-sm text-slate-600">Autores de plantillas de la Galería. Una plantilla es <strong>exitosa</strong> cuando otras <?= (int) $cfg['success_uses'] ?> personas o más la han usado. Ordenados por plantillas exitosas y luego por usos.</p>
</section>

<section class="<?= $card ?>" aria-labelledby="h-col">
  <h2 id="h-col" class="font-semibold">Ranking de autores</h2>
  <?php if ($rows === []): ?>
    <div class="text-center py-8">
      <p class="font-semibold">Aún no hay colaboradores destacados</p>
      <p class="text-sm text-slate-600">Cuando una plantilla llegue a <?= (int) $cfg['success_uses'] ?> usos por otras personas, su autor aparecerá aquí. ¿La primera puede ser la tuya?</p>
      <a class="mt-3 inline-flex items-center min-h-[44px] font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url($user ? 'creator_upload.php' : 'register.php')) ?>">Publicar en la Galería</a>
    </div>
  <?php else: ?>
    <ol class="mt-3 divide-y divide-rose-50">
      <?php foreach ($rows as $i => $r): ?>
        <li class="py-3 flex items-center gap-3">
          <span class="w-9 shrink-0 text-center text-sm font-bold text-slate-500">#<?= $i + 1 ?></span>
          <span class="min-w-0 flex-1">
            <span class="block break-words font-semibold"><?= e($r['alias']) ?></span>
            <?php if ($r['badges']): ?><span class="mt-1 flex gap-1"><?php foreach ($r['badges'] as $b): ?><?= Awards::badgeImg($b, 20) ?><?php endforeach; ?></span><?php endif; ?>
          </span>
          <span class="shrink-0 text-right text-xs text-slate-600"><strong class="block text-base text-rose-700"><?= (int) $r['successful'] ?> exitosa<?= $r['successful'] === 1 ? '' : 's' ?></strong><?= (int) $r['uses'] ?> usos · <?= (int) $r['templates'] ?> plantilla<?= $r['templates'] === 1 ? '' : 's' ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<section class="<?= $card ?> mt-4" aria-labelledby="h-prog">
  <h2 id="h-prog" class="font-semibold">Programa de creadores</h2>
  <p class="mt-1 text-sm text-slate-600">Publica tu plantilla, gana <?= (int) $cfg['share_pct'] ?> % en monedas por cada uso y desbloquea insignias y premios. <a class="font-semibold text-rose-700 underline" href="<?= e(url('terms.php#creadores')) ?>">Condiciones</a></p>
  <a class="mt-2 inline-flex items-center min-h-[44px] font-semibold text-rose-700 underline underline-offset-2" href="<?= e(url($user ? 'creator_upload.php' : 'register.php')) ?>">Publicar en la Galería</a>
</section>
<?php page_end();
