<?php
declare(strict_types=1);

/**
 * Cierra los meses pendientes del top de donadores (premios del mes). Idempotente: ejecutarlo dos veces no
 * duplica nada. Cron sugerido en producción: `5 6 1 * * php /ruta/bin/close_awards.php` (el mes de El Salvador
 * termina a las 06:00 UTC). Sin cron, el cierre ocurre solo al visitar top.php o el panel admin.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

$r = Awards::closePendingMonths();
echo $r['closed'] === [] ? "Sin meses pendientes.\n" : 'Meses cerrados: ' . implode(', ', $r['closed']) . ' (' . $r['granted'] . " premios).\n";
