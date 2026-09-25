<?php
declare(strict_types=1);

/**
 * Receptor de webhooks de Wompi El Salvador. Se registra por enlace de pago (urlWebhook),
 * así que no hay que configurar nada en el panel: https://<APP_URL>/webhook_wompi.php
 *
 * Sin sesión ni CSRF: la autenticidad la da la cabecera `wompi_hash` (HMAC-SHA256 hex del
 * cuerpo crudo con el API Secret), validada con hash_equals() ANTES de interpretar el JSON.
 *
 * Códigos: 401 firma inválida · 400 cuerpo ilegible · 404 referencia desconocida ·
 *          422 monto/moneda no coinciden · 500 error interno (Wompi reintenta) · 200 resto.
 */
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/Payments.php';

header('Content-Type: text/plain; charset=utf-8');

/** Cabecera `wompi_hash`. Apache/nginx descartan de $_SERVER las cabeceras con "_", así que se busca también en bruto. */
function wompi_hash_header(): string
{
    if (!empty($_SERVER['HTTP_WOMPI_HASH'])) {
        return (string) $_SERVER['HTTP_WOMPI_HASH'];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'wompi_hash') === 0) {
                return (string) $value;
            }
        }
    }
    return '';
}

/** Contexto del webhook en curso (solo datos no sensibles: nunca la firma, el secreto ni el cuerpo). */
$GLOBALS['wlog'] = ['ref' => '-', 'result' => '-', 'tx' => '-'];

/** Un solo renglón por webhook en error_log, para diagnosticar pagos que no se habilitan. */
function respond(int $status, string $body): never
{
    $c = $GLOBALS['wlog'];
    error_log(sprintf('Wompi webhook -> HTTP %d "%s" ref=%s resultado=%s tx=%s ip=%s', $status, $body, $c['ref'], $c['result'], $c['tx'], client_ip()));
    http_response_code($status);
    exit($body);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, '');
}

// Diagnóstico de entrega: qué llegó (solo nombres de cabecera y tamaños; nunca valores ni cuerpo).
$seenHeaders = [];
$names = array_map('strval', array_keys($_SERVER));
if (function_exists('apache_request_headers')) {
    $names = array_merge($names, array_map('strval', array_keys(apache_request_headers())));   // Apache/LiteSpeed dejan aquí las cabeceras con "_"
}
foreach ($names as $k) {
    if (stripos($k, 'WOMPI') !== false) {
        $seenHeaders[] = $k;
    }
}
error_log(sprintf('Wompi webhook recibido: ip=%s bytes=%d content-type=%s cabeceras_wompi=[%s] wompi_hash=%s',
    client_ip(), (int) ($_SERVER['CONTENT_LENGTH'] ?? 0), (string) ($_SERVER['CONTENT_TYPE'] ?? '-'),
    implode(',', $seenHeaders), wompi_hash_header() !== '' ? 'presente' : 'AUSENTE'));

const WEBHOOK_MAX_BYTES = 65536;
$raw = (string) file_get_contents('php://input', false, null, 0, WEBHOOK_MAX_BYTES + 1);
if (strlen($raw) > WEBHOOK_MAX_BYTES) {
    respond(413, 'too large');
}

// 1) Autenticidad sobre el cuerpo tal cual llegó (sin decodificar ni reformatear).
if (!WompiClient::verifyWebhook($raw, wompi_hash_header(), wompi_config()['api_secret'])) {
    error_log('Wompi webhook: firma inválida (' . (wompi_hash_header() === '' ? 'cabecera wompi_hash ausente' : 'HMAC no coincide: revisa WOMPI_API_SECRET') . ')');
    respond(401, 'invalid signature');
}

// 2) Solo ahora se interpreta el contenido.
$payload = json_decode($raw, true);
$tx = is_array($payload) ? WompiClient::parseWebhook($payload) : null;
if ($tx === null) {
    respond(400, 'bad payload');
}
$GLOBALS['wlog'] = ['ref' => $tx['identifier'], 'result' => $tx['result'], 'tx' => $tx['transaction_id'] !== '' ? $tx['transaction_id'] : '-'];

// Solo ExitosaAprobada concede acceso (Premium o plantilla); cualquier otro resultado se acusa y se ignora.
if ($tx['result'] !== WOMPI_RESULT_APPROVED) {
    respond(200, 'ignored');
}

// En producción una transacción de prueba (EsProductiva=false) jamás activa Premium.
if (wompi_config()['env'] === 'production' && !$tx['productive']) {
    error_log('Wompi: transacción no productiva rechazada en producción (' . $tx['identifier'] . ')');
    respond(200, 'ignored');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, user_id, amount_in_cents, currency, status, promo_code, template_id, tier_id, coins FROM payments WHERE reference = ?');
    $st->execute([$tx['identifier']]);
    $pay = $st->fetch();

    if (!$pay) {
        $pdo->rollBack();
        respond(404, 'unknown reference');
    }

    // El monto pagado y la moneda deben coincidir EXACTAMENTE con lo que registramos al crear el
    // enlace. El payload no trae moneda: Wompi SV solo opera en USD, así que se exige que el pago
    // se hubiera creado en USD (y el monto se compara en centavos enteros).
    if ((int) $pay['amount_in_cents'] !== $tx['amount_cents'] || $pay['currency'] !== WOMPI_CURRENCY) {
        $pdo->rollBack();
        error_log("Wompi: monto/moneda no coinciden para {$tx['identifier']} (esperado {$pay['amount_in_cents']} {$pay['currency']}, recibido {$tx['amount_cents']})");
        respond(422, 'mismatch');
    }

    // Idempotente y a prueba de carreras: la condición `status <> 'APPROVED'` hace que solo UNA
    // entrega (aunque Wompi reintente o lleguen dos a la vez) cambie la fila y dispare los efectos.
    $st = $pdo->prepare("UPDATE payments SET status = 'APPROVED', wompi_transaction_id = ?, updated_at = UTC_TIMESTAMP()
                          WHERE id = ? AND status <> 'APPROVED'");
    $st->execute([$tx['transaction_id'], $pay['id']]);

    // Efecto del pago (plan, bono, monedas, plantilla, cupón): idempotente por `fulfilled_at` con FOR UPDATE.
    Payments::fulfill($pdo, (int) $pay['id'], 'wompi');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Wompi webhook: ' . $e->getMessage());
    respond(500, 'error');   // Wompi reintentará
}

respond(200, 'ok');
