<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Paso 2 del acceso con Google: valida el `state`, canjea el código por un access_token, lee el
 * perfil, resuelve la cuenta (crear/vincular/reutilizar) y abre sesión.
 *
 * Cualquier fallo cierra el flujo con un mensaje corto en /login.php: al usuario nunca se le
 * muestran detalles de OAuth, y al servidor se le registra el motivo en el log.
 */

$backToLogin = static function (string $message): never {
    flash($message);
    redirect('login.php');
};

// El usuario canceló en la pantalla de Google (o Google denegó el acceso): no hay `code` que canjear.
$googleError = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
if ($googleError !== '') {
    error_log('Google devolvió un error en el callback: ' . $googleError);
    $backToLogin($googleError === 'access_denied'
        ? 'Cancelaste el acceso con Google.'
        : 'No se pudo completar el acceso con Google. Inténtalo de nuevo.');
}

// 1) state: un solo uso y con caducidad. Sin esto, un atacante podría pegar su propio enlace de
//    callback y dejar al usuario con la sesión iniciada en la cuenta de Google del atacante.
$state = GoogleState::consume($_SESSION, is_string($_GET['state'] ?? null) ? $_GET['state'] : '');
if ($state === null) {
    error_log('Callback de Google con state inválido, caducado o ausente.');
    $backToLogin('La sesión de acceso con Google caducó. Inténtalo de nuevo.');
}

$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if ($code === '' || strlen($code) > 2048) {
    $backToLogin('Google no devolvió un código de acceso válido. Inténtalo de nuevo.');
}

// 2) canje del código (con el verificador PKCE) + perfil del usuario.
try {
    $client  = google_client();
    $token   = $client->exchangeCode($code, $state['verifier']);
    $profile = $client->userInfo($token['access_token']);
    $result  = GoogleAccount::signIn($profile['sub'], $profile['email'], $profile['email_verified'], $profile['picture'], $state['ref']);
} catch (GoogleAuthException $e) {
    error_log('Fallo el acceso con Google: ' . $e->getMessage());
    $backToLogin($e->publicMessage);
}

// 3) Sesión: login_user() regenera el id de sesión (evita session fixation) y rota el token CSRF.
login_user($result['id']);
flash($result['created']
    ? '¡Cuenta creada con Google! Ya puedes crear tu página.'
    : 'Sesión iniciada con Google.');

// Las cuentas nuevas aterrizan en «crear»; las que ya existían, en «mis páginas».
redirect(auth_next_path($state['next'], $result['created'] ? 'create.php' : 'dashboard.php'));
