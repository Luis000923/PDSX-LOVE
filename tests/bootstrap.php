<?php
declare(strict_types=1);

define('NO_SESSION', true);          // sin sesión ni cabeceras en CLI
// BD desechable: ninguna prueba debe tocar la base real.
putenv('DB_PATH=' . sys_get_temp_dir() . '/lovepages-test-' . getmypid() . '.sqlite');
register_shutdown_function(static function (): void {
    foreach (glob(sys_get_temp_dir() . '/lovepages-test-' . getmypid() . '.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
});
putenv('WOMPI_EVENTS_SECRET=evt_test');
putenv('WOMPI_INTEGRITY_SECRET=int_test');
putenv('WOMPI_PUBLIC_KEY=pub_test');
require dirname(__DIR__) . '/src/bootstrap.php';
