<?php
declare(strict_types=1);

/**
 * Receptor de eventos de Wompi. Configurar en el panel de Wompi:
 *   URL de eventos: https://pdsx.org/love/webhook_wompi.php
 * Sin sesión ni CSRF: la autenticidad se garantiza con el checksum firmado (events_secret).
 */
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 65536);
$event = json_decode((string) $raw, true);

if (!is_array($event) || !wompi_verify_event($event)) {
    http_response_code(401);
    exit('invalid signature');
}

if (($event['event'] ?? '') !== 'transaction.updated') {
    exit('ignored');   // 200: evento válido que no nos interesa
}

$tx = $event['data']['transaction'] ?? [];
$reference = (string) ($tx['reference'] ?? '');
$status    = (string) ($tx['status'] ?? '');
$amount    = (int) ($tx['amount_in_cents'] ?? 0);
$currency  = (string) ($tx['currency'] ?? '');

if (!in_array($status, ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true)) {
    exit('ignored');   // p.ej. PENDING
}

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, user_id, amount_in_cents, currency, status, promo_code FROM payments WHERE reference = ?');
    $st->execute([$reference]);
    $pay = $st->fetch();

    if (!$pay) {
        $pdo->rollBack();
        http_response_code(404);
        exit('unknown reference');
    }
    // Defensa: el monto/moneda deben coincidir con lo que registramos al iniciar el pago.
    if ((int) $pay['amount_in_cents'] !== $amount || $pay['currency'] !== $currency) {
        $pdo->rollBack();
        error_log("Wompi: monto/moneda no coinciden para $reference");
        http_response_code(422);
        exit('mismatch');
    }

    // Idempotencia: un pago ya aprobado no se modifica.
    if ($pay['status'] !== 'APPROVED') {
        $pdo->prepare("UPDATE payments SET status = ?, wompi_transaction_id = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$status, (string) ($tx['id'] ?? ''), $pay['id']]);
        if ($status === 'APPROVED') {
            $pdo->prepare('UPDATE users SET is_premium = 1 WHERE id = ?')->execute([$pay['user_id']]);
            // El contador del código solo avanza con pagos realmente aprobados.
            if (!empty($pay['promo_code'])) {
                $pdo->prepare('UPDATE promos SET uses = uses + 1 WHERE code = ?')->execute([$pay['promo_code']]);
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Wompi webhook: ' . $e->getMessage());
    http_response_code(500);   // Wompi reintentará
    exit('error');
}

echo 'ok';
