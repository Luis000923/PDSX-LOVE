<?php
declare(strict_types=1);

/**
 * Páginas de error. Autocontenidas a propósito: sin BD, sin sesión de usuario y sin CDN, porque
 * deben poder mostrarse justo cuando la aplicación está degradada (p. ej. un 500 por la base caída).
 * Reutilizan la CSP con nonce que ya fijó bootstrap.php.
 */

/** @return array<int, array{title:string, text:string}> mensajes por código (texto plano; se escapan al pintar) */
function error_messages(): array
{
    return [
        403 => ['title' => 'Acceso denegado', 'text' => 'No tienes permiso para ver esto, o tu sesión expiró. Recarga la página e inténtalo de nuevo.'],
        404 => ['title' => 'Página no encontrada', 'text' => 'El enlace no existe o la plantilla ya no está disponible. Revisa la dirección o vuelve al inicio.'],
        410 => ['title' => 'Esta página expiró', 'text' => 'La vigencia de esta página de pareja terminó. Si es tuya, entra a tu cuenta y renuévala desde «Mis páginas».'],
        500 => ['title' => 'Algo salió mal', 'text' => 'Tuvimos un problema de nuestro lado. Ya quedó registrado; inténtalo de nuevo en unos minutos.'],
    ];
}

/** Devuelve el código si es uno de los soportados; si no, null. */
function error_code(mixed $raw): ?int
{
    $code = filter_var($raw, FILTER_VALIDATE_INT);
    return is_int($code) && array_key_exists($code, error_messages()) ? $code : null;
}

/** Pinta la vista de error con su código HTTP y termina. $text sustituye al mensaje por defecto. */
function render_error(int $code, ?string $text = null): never
{
    $msg = error_messages()[$code] ?? error_messages()[500];
    $code = array_key_exists($code, error_messages()) ? $code : 500;

    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    $home  = e(url('index.php'));
    $login = e(url('dashboard.php'));
    $css   = e(url('assets/css/errors.css'));
    $logo  = e(url('assets/img/logo.svg'));
    $icon  = e(url('assets/img/favicon.svg'));
    $digits = preg_replace('/(\d)$/', '<span>$1</span>', (string) $code);
    $title = e($msg['title']);
    $body  = e($text ?? $msg['text']);
    $secondary = match ($code) {
        410, 403 => '<a class="btn" href="' . $login . '">Mis páginas</a>',
        500      => '<a class="btn" href="">Reintentar</a>',   // enlace a la propia URL: la CSP no admite javascript:
        default  => '',
    };

    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$code} · {$title} · LovePages</title>
<link rel="icon" type="image/svg+xml" href="{$icon}">
<link rel="stylesheet" href="{$css}">
</head>
<body>
<header class="err-head"><a href="{$home}" aria-label="PDSX · LovePages"><img src="{$logo}" alt="PDSX" width="80" height="32"></a></header>
<main class="err-main"><div class="err-card">
  <p class="err-code" aria-hidden="true">{$digits}</p>
  <h1 class="err-title">{$title}</h1>
  <p class="err-text">{$body}</p>
  <div class="err-actions"><a class="btn btn-primary" href="{$home}">Ir al inicio</a>{$secondary}</div>
</div></main>
</body>
</html>
HTML;
    exit;
}

/** Excepción no capturada: se registra y se muestra el 500 limpio (con APP_DEBUG=1 se deja el detalle de PHP). */
function handle_uncaught(Throwable $e): void
{
    error_log('Uncaught ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (env('APP_DEBUG', '0') === '1') {
        throw $e;
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    render_error(500);
}
