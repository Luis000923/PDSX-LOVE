<?php
declare(strict_types=1);

/**
 * Bootstrap común: entorno, cabeceras de seguridad, sesión, CSRF y auth.
 * Definir NO_SESSION antes de incluirlo (p.ej. webhooks) para omitir sesión/CSP.
 */
define('ROOT', dirname(__DIR__));

// ---------- Entorno (.env) ----------
function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = getenv('ENV_FILE') ?: ROOT . '/.env';   // ENV_FILE: solo para tests
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim(preg_replace('/\s+#.*$/', '', $line)); // quita comentarios en línea
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    // El entorno real del proceso (Docker, CI) tiene prioridad sobre el archivo .env.
    $real = getenv($key);
    return ($real !== false && $real !== '') ? $real : ($vars[$key] ?? $default);
}

$debug = env('APP_DEBUG', '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

require_once ROOT . '/config/database.php';
require_once ROOT . '/config/wompi.php';
require_once ROOT . '/src/Template.php';
require_once ROOT . '/src/PhpTemplate.php';
require_once ROOT . '/src/Admin.php';
require_once ROOT . '/src/Access.php';
require_once ROOT . '/src/Coins.php';
require_once ROOT . '/src/Sites.php';
require_once ROOT . '/src/AdminUsers.php';
require_once ROOT . '/src/Payments.php';
require_once ROOT . '/src/Profile.php';
require_once ROOT . '/src/layout.php';
require_once ROOT . '/src/errors.php';

// ---------- Helpers ----------
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, 303);
    exit;
}

function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------- Cabeceras y sesión ----------
if (!defined('NO_SESSION')) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.tailwindcss.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; form-action 'self' https://*.wompi.sv");

    $secure = str_starts_with((string) env('APP_URL'), 'https://');
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('lp_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string) parse_url((string) env('APP_URL'), PHP_URL_PATH), '/') . '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',   // Lax permite volver de Wompi con la sesión activa
    ]);
    session_start();

    // Un fallo no previsto muestra el 500 con la marca en vez de una pantalla en blanco.
    if (PHP_SAPI !== 'cli') {
        set_exception_handler('handle_uncaught');
    }
}

// ---------- CSRF ----------
function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Aborta con 403 si el token POST no coincide. */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        render_error(403, 'Tu sesión expiró o el formulario ya no es válido. Recarga la página e inténtalo de nuevo.');
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    csrf_verify();
}

// ---------- Auth ----------
/** Usuario de la sesión leído de la BD; null (y sesión limpia) si no existe o está suspendido. Sin caché. */
function load_session_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, email, password_hash, is_premium, is_admin, is_suspended, membership_tier_id, membership_expires_at FROM users WHERE id = ?');
    $st->execute([(int) $_SESSION['uid']]);
    $row = $st->fetch() ?: null;
    // Suspendida, o la contraseña cambió desde otro dispositivo (revoca esta sesión: quien robó la cookie
    // queda fuera en cuanto el dueño legítimo cambia su contraseña, sin esperar a que expire).
    $pwChanged = $row !== null && !hash_equals((string) ($_SESSION['pwv'] ?? ''), (string) $row['password_hash']);
    if ($row !== null && ((int) $row['is_suspended'] === 1 || $pwChanged)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        return null;
    }
    unset($row['password_hash']);
    return $row;
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = load_session_user();
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function login_user(int $id): void
{
    session_regenerate_id(true);   // evita session fixation
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$id]);
    $hash = (string) ($st->fetchColumn() ?: '');
    $_SESSION = ['uid' => $id, 'csrf' => bin2hex(random_bytes(32)), 'pwv' => $hash];
}

// ---------- Flash ----------
function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}
