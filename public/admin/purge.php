<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/admin_layout.php';

/** Zona de peligro: limpieza total de usuarios y compras (para vaciar datos de prueba). Irreversible. */
$admin = Admin::guard();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try {
        $r = AdminUsers::purgeAll((string) ($_POST['phrase'] ?? ''), (string) ($_POST['secret'] ?? ''), (string) ($_POST['reason'] ?? ''), (int) $admin['id']);
        flash(sprintf('Limpieza completada: %d usuarios, %d compras y %d páginas eliminados.', $r['users'], $r['payments'], $r['sites']));
        redirect('admin/purge.php');
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
}

$n = AdminUsers::purgePreview();
$hasPw = (function () use ($admin): bool {
    $st = db()->prepare('SELECT has_password FROM users WHERE id = ?');
    $st->execute([(int) $admin['id']]);
    return (int) $st->fetchColumn() === 1;
})();

admin_page_start('Limpieza total', 'purge', 'Elimina usuarios, compras y páginas de una sola vez. Irreversible.');
admin_errors($errors);
?>
<section class="<?= ADMIN_CARD_CLS ?> max-w-2xl border-rose-300" aria-labelledby="h-purge">
  <h2 id="h-purge" class="text-lg font-bold text-rose-800">Zona de peligro</h2>
  <p class="mt-2 text-sm text-slate-700">Se eliminará <strong>ahora mismo</strong>:</p>
  <ul class="mt-2 text-sm text-slate-700 list-disc pl-5 space-y-1">
    <li><strong><?= $n['users'] ?></strong> usuarios que no son administradores (con sus páginas, monedas, creaciones y canjes de cupón)</li>
    <li><strong><?= $n['payments'] ?></strong> registros de compra (también los de administradores)</li>
    <li><strong><?= $n['sites'] ?></strong> páginas publicadas y sus archivos, y <strong><?= number_format($n['coins']) ?></strong> monedas en circulación</li>
  </ul>
  <p class="mt-3 text-sm text-slate-600">Se conservan: administradores, plantillas, planes, cupones (con los usos en 0), ajustes y el registro de actividad. <strong>No se puede deshacer.</strong> Haz una copia de la base de datos antes si tienes dudas.</p>

  <form method="post" class="mt-5 space-y-3" autocomplete="off">
    <?= csrf_field() ?>
    <div><label class="block text-sm font-semibold mb-1" for="phrase">Escribe <span class="font-mono"><?= e(AdminUsers::PURGE_PHRASE) ?></span></label>
      <input id="phrase" name="phrase" required maxlength="30" class="<?= ADMIN_INPUT_CLS ?> font-mono"></div>
    <div><label class="block text-sm font-semibold mb-1" for="secret"><?= $hasPw ? 'Tu contraseña de administrador' : 'Tu correo de administrador (cuenta de Google)' ?></label>
      <input id="secret" name="secret" required maxlength="255" type="<?= $hasPw ? 'password' : 'email' ?>" class="<?= ADMIN_INPUT_CLS ?>"></div>
    <div><label class="block text-sm font-semibold mb-1" for="reason">Motivo</label>
      <input id="reason" name="reason" required maxlength="200" placeholder="Limpiar datos de prueba antes del lanzamiento" class="<?= ADMIN_INPUT_CLS ?>"></div>
    <button class="<?= ADMIN_BTN_DANGER_CLS ?>">Eliminar todo definitivamente</button>
  </form>
</section>
<?php admin_page_end(); ?>
