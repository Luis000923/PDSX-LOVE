<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Destino de ErrorDocument (Apache) y de los errores que no pasan por PHP (URL inexistente,
 * 403 de un .php bloqueado...). Apache conserva el código original en REDIRECT_STATUS; ?c=
 * solo sirve para probarlo a mano. Cualquier otro valor cae en 404.
 */
render_error(error_code($_SERVER['REDIRECT_STATUS'] ?? null) ?? error_code($_GET['c'] ?? null) ?? 404);
