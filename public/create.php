<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

const FREE_SITE_LIMIT = 3;

$user = require_login();
$pdo  = db();
$templates = $pdo->query('SELECT id, name, is_premium FROM templates WHERE is_active = 1 ORDER BY is_premium, id')->fetchAll();

$data = array_fill_keys(array_keys(Template::FIELDS), '');
$errors = [];
$chosen = (int) ($templates[0]['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$data, $errors] = Template::sanitize($_POST);
    $chosen = (int) ($_POST['template_id'] ?? 0);

    $tpl = null;
    foreach ($templates as $t) {
        if ((int) $t['id'] === $chosen) {
            $tpl = $t;
        }
    }
    if (!$tpl) {
        $errors['template_id'] = 'Plantilla inválida.';
    } elseif ($tpl['is_premium'] && !$user['is_premium']) {
        $errors['template_id'] = 'Esta plantilla requiere Premium.';
    }

    if (!$errors && !$user['is_premium']) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM user_sites WHERE user_id = ?');
        $st->execute([$user['id']]);
        if ((int) $st->fetchColumn() >= FREE_SITE_LIMIT) {
            $errors['template_id'] = 'Alcanzaste el límite de páginas gratis. Pásate a Premium.';
        }
    }

    if (!$errors) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        for ($i = 0; $i < 5; $i++) {          // reintenta ante colisión de slug
            $slug = '';
            for ($j = 0; $j < 8; $j++) {
                $slug .= 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)];
            }
            try {
                $pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data) VALUES (?, ?, ?, ?)')
                    ->execute([$user['id'], $chosen, $slug, $json]);
                flash('¡Tu página está lista! Comparte el enlace.');
                redirect('dashboard.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
            }
        }
        $errors['template_id'] = 'No se pudo crear la página. Inténtalo otra vez.';
    }
}

$labels = ['your_name' => 'Tu nombre', 'partner_name' => 'Nombre de tu pareja', 'start_date' => 'Fecha en que empezaron', 'message' => 'Tu mensaje'];

page_start('Crear página');
?>
<h1 class="text-2xl font-bold mt-4 mb-6">Crea tu página</h1>
<form method="post" class="space-y-4">
  <?= csrf_field() ?>
  <fieldset class="grid grid-cols-2 gap-3">
    <?php foreach ($templates as $t): $locked = $t['is_premium'] && !$user['is_premium']; ?>
      <label class="rounded-2xl border bg-white p-3 text-center text-sm <?= $locked ? 'opacity-50' : 'cursor-pointer has-[:checked]:border-rose-500 has-[:checked]:ring-2 has-[:checked]:ring-rose-300' ?>">
        <input class="sr-only" type="radio" name="template_id" value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $chosen && !$locked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
        <span class="block text-2xl text-rose-500">♥</span><?= e($t['name']) ?>
      </label>
    <?php endforeach; ?>
  </fieldset>
  <?php if (isset($errors['template_id'])): ?><p class="text-sm text-rose-700"><?= e($errors['template_id']) ?></p><?php endif; ?>

  <?php foreach ($labels as $f => $label): ?>
    <div>
      <label class="block text-sm font-semibold mb-1" for="<?= $f ?>"><?= e($label) ?></label>
      <?php if ($f === 'message'): ?>
        <textarea id="<?= $f ?>" name="<?= $f ?>" rows="5" maxlength="<?= Template::FIELDS[$f] ?>" required class="<?= INPUT_CLS ?>"><?= e($data[$f]) ?></textarea>
      <?php else: ?>
        <input id="<?= $f ?>" name="<?= $f ?>" type="<?= $f === 'start_date' ? 'date' : 'text' ?>" value="<?= e($data[$f]) ?>"
               maxlength="<?= Template::FIELDS[$f] ?>" <?= $f === 'start_date' ? 'max="' . date('Y-m-d') . '"' : '' ?> required class="<?= INPUT_CLS ?>">
      <?php endif; ?>
      <?php if (isset($errors[$f])): ?><p class="text-sm text-rose-700 mt-1"><?= e($errors[$f]) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <button class="<?= BTN_CLS ?>">Crear página</button>
</form>
<?php page_end();
