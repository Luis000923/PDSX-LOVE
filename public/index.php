<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$templates = db()->query('SELECT name, description, thumbnail, is_premium FROM templates WHERE is_active = 1 ORDER BY is_premium, id')->fetchAll();

page_start('Crea tu página de amor');
?>
<section class="text-center pt-6">
  <h1 class="text-4xl font-bold leading-tight">Una página de amor<br><span class="text-rose-600">en 2 minutos</span></h1>
  <p class="mt-4 text-slate-600">Contador de días, carta digital y un link para compartir con esa persona especial.</p>
  <a href="<?= e(url(current_user() ? 'create.php' : 'register.php')) ?>" class="mt-6 inline-block <?= BTN_CLS ?>">Crear mi página gratis</a>
</section>

<section class="mt-10 grid grid-cols-2 gap-3">
  <?php foreach ($templates as $t): ?>
    <div class="rounded-2xl bg-white border border-rose-100 p-4 text-center">
      <?php if ($t['thumbnail']): ?>
        <img src="<?= e(url('assets/thumbs/' . $t['thumbnail'])) ?>" alt="" width="200" height="120" class="w-full h-24 object-cover rounded-xl mb-2">
      <?php else: ?>
        <p class="text-3xl text-rose-500">♥</p>
      <?php endif; ?>
      <p class="font-semibold mt-1"><?= e($t['name']) ?></p>
      <?php if ($t['description']): ?><p class="text-xs text-slate-500 mt-1"><?= e($t['description']) ?></p><?php endif; ?>
      <p class="text-xs mt-1 <?= $t['is_premium'] ? 'text-amber-600' : 'text-emerald-600' ?>">
        <?= $t['is_premium'] ? 'Premium' : 'Gratis' ?>
      </p>
    </div>
  <?php endforeach; ?>
</section>
<?php page_end();
