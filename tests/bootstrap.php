<?php
declare(strict_types=1);

define('NO_SESSION', true);          // sin sesión ni cabeceras en CLI
// BD desechable: ninguna prueba debe tocar la base real. Por defecto apunta al MySQL local de
// desarrollo/CI; el nombre DEBE terminar en `_test` porque las pruebas la vacían y recrean.
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'lovepages_test',
          'DB_USER' => 'lovepages', 'DB_PASSWORD' => 'lovepages'] as $k => $v) {
    if ((string) getenv($k) === '') {
        putenv("$k=$v");
    }
}
if (!str_ends_with((string) getenv('DB_NAME'), '_test')) {
    fwrite(STDERR, "Los tests exigen DB_NAME terminado en _test (recibido: " . getenv('DB_NAME') . ").\n");
    exit(1);
}
putenv('ENV_FILE=/dev/null');          // ignora el .env local: los tests son herméticos
// Credenciales falsas de Wompi SV (las variables reales del proceso ganan sobre .env).
putenv('WOMPI_CLIENT_ID=client_test');
putenv('WOMPI_API_SECRET=secret_test');
putenv('WOMPI_ENV=sandbox');
putenv('WOMPI_TOKEN_URL');           // sin simulador por defecto
putenv('WOMPI_API_URL');
putenv('PREMIUM_PRICE_USD=4.99');
require dirname(__DIR__) . '/src/bootstrap.php';
