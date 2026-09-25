<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Paso 1 del acceso con Google: genera `state` + verificador PKCE, los guarda en la sesión y
 * manda al usuario a la pantalla de consentimiento de Google. Aquí no se valida nada todavía:
 * la protección contra CSRF la da el `state`, que vuelve en el callback y se compara allí.
 */

if (current_user()) {
    redirect('index.php');   // ya hay sesión: no hace falta volver a autorizar la misma cuenta
}

$unavailable = 'El acceso con Google no está disponible ahora mismo. Puedes entrar con tu correo y contraseña.';

if (!google_configured()) {
    error_log('Se intentó entrar con Google sin credenciales válidas (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI).');
    render_error(503, $unavailable);
}

try {
    $client = google_client();
    $state  = GoogleState::issue(
        $_SESSION,
        is_string($_GET['next'] ?? null) ? $_GET['next'] : '',
        is_string($_GET['ref'] ?? null) ? $_GET['ref'] : ''
    );
} catch (GoogleAuthException $e) {
    error_log('Google OAuth no disponible: ' . $e->getMessage());
    render_error(503, $unavailable);
}

// 302 hacia accounts.google.com. La CSP no aplica: el navegador abandona la página hacia otro host.
redirect($client->authorizeUrl($state['state'], $state['verifier']));
