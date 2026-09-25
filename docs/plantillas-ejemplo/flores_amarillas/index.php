<?php /** Ejemplo de plantilla PHP. Variables: $t (escapadas), $nonce, $ad_slot y $assets (URL base de tus css/js/imágenes). */ ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $t['your_name'] ?> ♥ <?= $t['partner_name'] ?></title>
<link rel="stylesheet" href="<?= $assets . 'css/flores.css' ?>">
</head>
<body>
<div id="petals" aria-hidden="true"></div>
<main class="card">
  <p class="eyebrow">Para <?= $t['partner_name'] ?></p>
  <h1><?= $t['your_name'] ?> 🌻 <?= $t['partner_name'] ?></h1>
  <p class="days"><strong><?= $t['days_together'] ?></strong> días juntos</p>
  <p class="msg"><?= $t['message'] ?></p>
  <?php
  // Lógica propia de la plantilla: saludo según los días (funciones seguras solamente).
  $d = (int) $t['days_together'];
  $note = $d >= 365 ? 'Más de un año de girasoles.' : ($d >= 30 ? 'Ya son meses floreciendo.' : 'Apenas empieza el jardín.');
  ?>
  <p class="note"><?= $note ?></p>
  <?= $ad_slot ?>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script nonce="<?= $nonce ?>" src="<?= $assets . 'js/flores.js' ?>"></script>
</body>
</html>
