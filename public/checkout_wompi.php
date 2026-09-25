<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/Payments.php';

/**
 * Inicia un pago (Wompi El Salvador): un nivel de membresía (POST tier_id) o una plantilla extra (POST template_id):
 *   1. el servidor lee el monto (USD) de la BD y registra un pago PENDING,
 *   2. crea un Enlace de Pago vía API (token OAuth + POST /EnlacePago),
 *   3. redirige al usuario a la urlEnlace que devuelve Wompi.
 * La activación de Premium NO ocurre aquí: solo el webhook firmado la concede.
 */
require_post();
$user = require_login();

$pdo = db();

// Producto: una membresía (tier_id) o una plantilla extra (template_id). El id solo SELECCIONA la
// fila; precio, nombre, descuento y si es comprable salen siempre de la BD.
$tpl = null;
$tier = null;
$userTier = Access::userTier($user);
$tierId = (int) ($_POST['tier_id'] ?? 0);
$packCents = (int) ($_POST['pack'] ?? 0);   // recarga de monedas: solo se acepta un paquete de la lista fija
$coins = 0;
$templateId = (int) ($_POST['template_id'] ?? 0);

if ($packCents > 0) {
    if (!Coins::isPack($packCents)) {
        flash('Ese paquete no existe.');
        redirect('tienda.php#monedas');
    }
    $coins = Coins::packCoins($packCents, $userTier);   // la bonificación sale del plan en la BD, no del cliente
} elseif ($tierId > 0) {
    $tier = Access::tier($tierId);
    if ($tier === null) {
        flash('Ese plan no está disponible.');
        redirect('dashboard.php');
    }
    if (!Access::canPurchaseTier($user, $tier)) {   // renovar el mismo plan sí; uno inferior al vigente no
        flash('Ya tienes un plan superior vigente.');
        redirect('dashboard.php');
    }
} elseif ($templateId > 0) {
    $st = $pdo->prepare('SELECT id, name, price_usd, is_premium FROM templates WHERE id = ? AND is_active = 1 AND kind NOT IN (\'user\', \'utpl\')');
    $st->execute([$templateId]);
    $tpl = $st->fetch() ?: null;
    if ($tpl === null || !Access::isPurchasable($tpl)) {
        flash('Esa plantilla no se puede comprar por separado.');
        redirect('create.php');
    }
    // Se compra para desbloquearla, o como página extra cuando se agotó el límite del plan.
    $unlocked = Access::canUse($user, $tpl, Access::purchasedTemplateIds((int) $user['id']));
    if ($unlocked && !Access::atSiteLimit($user)) {
        flash('Aún tienes páginas disponibles en tu plan.');
        redirect('create.php');
    }
} else {
    redirect('dashboard.php');
}
$backPath = $coins > 0 ? 'tienda.php#monedas' : ($tpl ? 'create.php' : 'tienda.php#membresias');

// El monto lo fija el servidor; jamás se acepta del cliente. Del formulario solo llegan el id de
// plantilla y el código de promoción, que se resuelven contra la BD (nunca el precio ni el descuento).
$amountInCents = $coins > 0 ? $packCents : ($tpl ? Access::extraTemplatePriceInCents($tpl, $userTier) : Access::tierPriceInCents((array) $tier));
$promoCode     = null;

// Un cupón rebaja el PRECIO en USD (también en recargas de monedas: las monedas que se reciben no cambian).
if (($code = trim((string) ($_POST['promo'] ?? ''))) !== '') {
    $promo = Admin::findUsablePromo($code);
    if (!$promo) {
        flash('Ese código de promoción no es válido, ya caducó o se agotó.');
        redirect($coins > 0 ? $backPath : ($tpl ? 'create.php' : 'dashboard.php?offer=code#premium'));
    }
    if (!Payments::promoApplies($promo, $tier ? (int) $tier['id'] : null, $tpl !== null, $coins > 0)) {
        flash('Ese código no aplica a este producto.');
        redirect($backPath);
    }
    if (Payments::alreadyRedeemed($pdo, (int) $promo['id'], (int) $user['id'])) {
        flash('Ya usaste este código de promoción.');
        redirect($backPath);
    }
    $promoCode     = (string) $promo['code'];
    $amountInCents = Payments::discountedCents($amountInCents, (int) $promo['discount_percent']);
    if ($coins > 0 && $amountInCents === 0) {   // monedas gratis por cupón: no se permite (el 100 % solo vale para membresías y plantillas)
        flash('Ese cupón no se puede usar en recargas de monedas.');
        redirect($backPath);
    }
    if ($amountInCents === 0) {   // cupón 100 %: sin pasarela
        $r = Payments::redeemFree($pdo, (int) $user['id'], $promo, $tier ? (int) $tier['id'] : null, $tpl ? (int) $tpl['id'] : null);
        flash($r['ok'] ? '¡Cupón aplicado! Tu compra quedó activada.' : (string) $r['error']);
        redirect($r['ok'] ? ($tpl ? 'create.php' : 'dashboard.php') : $backPath);
    }
}

if (!wompi_configured()) {
    error_log('Wompi no configurado (WOMPI_CLIENT_ID / WOMPI_API_SECRET).');
    flash('El pago no está disponible por ahora.');
    redirect($backPath);
}

// Freno a abusos: cada intento llama a una API externa y deja una fila.
$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'PENDING' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
$st->execute([$user['id']]);
if ((int) $st->fetchColumn() >= 5) {
    flash('Has iniciado varios pagos seguidos. Espera un rato o completa el que tienes abierto.');
    redirect($backPath);
}

// `reference` guarda el identificadorEnlaceComercio que enviamos a Wompi y que vuelve en el webhook.
$identifier = 'LP-' . $user['id'] . '-' . bin2hex(random_bytes(8));
$pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, currency, promo_code, template_id, tier_id, coins) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$user['id'], $identifier, $amountInCents, WOMPI_CURRENCY, $promoCode, $tpl ? (int) $tpl['id'] : null, $tier ? (int) $tier['id'] : null, $coins > 0 ? $coins : null]);
$paymentId = (int) $pdo->lastInsertId();

$link = null;
try {
    $link = WompiClient::fromEnv()->createPaymentLink(
        $identifier,
        $amountInCents,
        $coins > 0 ? "LovePages · $coins monedas" : ($tpl ? 'LovePages · ' . $tpl['name'] : 'LovePages ' . $tier['name']),
        $coins > 0 ? "Recarga de $coins monedas (pago único)" . ($promoCode !== null ? ' con cupón' : '') : ($tpl ? 'Acceso a la plantilla (pago único)' : 'Membresía: más páginas, monedas de bono y más días de vida (pago único)'),
        url($backPath),
        url('webhook_wompi.php'),
    );
} catch (Throwable $e) {
    error_log('Wompi checkout: ' . $e->getMessage());
}

if ($link === null) {
    $pdo->prepare("UPDATE payments SET status = 'ERROR', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$paymentId]);
    flash('No pudimos iniciar el pago. Inténtalo de nuevo en unos minutos.');
    redirect($backPath);
}

$pdo->prepare("UPDATE payments SET link_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$link['link_id'], $paymentId]);
redirect($link['url']);
