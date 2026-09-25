<?php
declare(strict_types=1);

/** Cron: liquida las ganancias pendientes de todos los creadores (idempotente). Uso: php bin/settle_creators.php */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';
$n = CreatorEarnings::settleAll();
echo "Monedas acreditadas: $n\n";
