<?php
declare(strict_types=1);

/**
 * Elimina los pagos PENDING de Wompi que no se completaron pasado PAYMENT_PENDING_TTL_MINUTES (60 por defecto).
 * Idempotente. Cron sugerido: cada 10 min: php /ruta/bin/purge_pending_payments.php. Sin cron, la limpieza
 * también ocurre al iniciar cada checkout.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/Payments.php';

echo 'Pagos pendientes eliminados: ' . Payments::purgeExpiredPending(db()) . "\n";
