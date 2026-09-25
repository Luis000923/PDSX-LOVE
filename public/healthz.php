<?php
declare(strict_types=1);

/** Healthcheck para Docker/balanceador: comprueba que PHP y la BD responden. */
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    db()->query('SELECT 1')->fetchColumn();
    echo 'ok';
} catch (Throwable $e) {
    error_log('healthz: ' . $e->getMessage());
    http_response_code(503);
    echo 'db error';
}
