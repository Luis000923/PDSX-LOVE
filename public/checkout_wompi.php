<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Inicia el pago Premium: registra un pago PENDING y redirige al Web Checkout de Wompi. */
require_post();
$user = require_login();

if ($user['is_premium']) {
    redirect('dashboard.php');
}

$cfg = wompi_config();
if ($cfg['public_key'] === '' || $cfg['integrity_secret'] === '') {
    error_log('Wompi no configurado.');
    flash('El pago no está disponible por ahora.');
    redirect('dashboard.php');
}

// El monto lo fija el servidor; jamás se acepta del cliente. Del formulario solo
// llega el código de promoción, que se resuelve contra la BD (nunca el descuento).
$amountInCents = $cfg['price_in_cents'];
$promoCode     = null;

if (($code = trim((string) ($_POST['promo'] ?? ''))) !== '') {
    $promo = Admin::findUsablePromo($code);
    if (!$promo) {
        flash('Ese código de promoción no es válido, ya caducó o se agotó.');
        redirect('dashboard.php');
    }
    $promoCode     = (string) $promo['code'];
    $amountInCents = max(
        WOMPI_MIN_AMOUNT_IN_CENTS,
        (int) round($amountInCents * (100 - (int) $promo['discount_percent']) / 100)
    );
}

$reference = 'LP-' . $user['id'] . '-' . bin2hex(random_bytes(8));
db()->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, currency, promo_code) VALUES (?, ?, ?, ?, ?)')
    ->execute([$user['id'], $reference, $amountInCents, $cfg['currency'], $promoCode]);

redirect(wompi_checkout_url($reference, $amountInCents, url('dashboard.php')));
