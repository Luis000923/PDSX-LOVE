<?php
declare(strict_types=1);

/**
 * Google OAuth 2.0 (OpenID Connect) sin librerías de terceros: cURL nativo + PDO.
 * Docs: https://developers.google.com/identity/protocols/oauth2
 *
 * Flujo (ver public/auth/google.php y public/auth/google_callback.php):
 *   1. GET /auth/google.php      -> state + code_verifier nuevos en sesión, 302 a Google
 *   2. Google responde en        -> /auth/google_callback.php?code=...&state=...
 *   3. POST oauth2.googleapis.com/token (code + code_verifier PKCE) -> access_token
 *   4. GET  www.googleapis.com/oauth2/v3/userinfo (Bearer) -> sub, email, name, picture
 *
 * Defensa CSRF: `state` aleatorio de un solo uso y con caducidad (GoogleState). Defensa
 * contra intercepción del código: PKCE S256, que además impide reutilizar un `code` robado.
 * El correo solo se acepta si Google lo devuelve VERIFICADO (`email_verified`): sin esa
 * comprobación, cualquiera podría registrar una cuenta con el correo de otra persona.
 */

/** Endpoint de autorización (interfaz web de cuentas de Google). */
const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

/** Endpoint de canje de código por token. */
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

/** Endpoint de perfil (los scopes bastan para leerlo, no hace falta validar el id_token). */
const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

/** Scopes mínimos: identificar al usuario y leer su correo. Nada más. */
const GOOGLE_SCOPES = ['openid', 'email', 'profile'];

/** Clave del estado anti-CSRF dentro de la sesión. */
const GOOGLE_STATE_KEY = 'google_oauth_state';

/** Segundos de validez del `state` (10 min: de sobra para el viaje de ida y vuelta a Google). */
const GOOGLE_STATE_TTL = 600;

/** Fallo controlado al hablar con Google. El mensaje interno nunca sale al usuario. */
final class GoogleAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly string $publicMessage = 'No se pudo completar el acceso con Google. Inténtalo de nuevo.')
    {
        parent::__construct($message);
    }
}

// ------------------------------------------------------------- configuración ---

/**
 * Credenciales y URI de redirección. `GOOGLE_REDIRECT_URI` es opcional: si falta se usa la
 * ruta de la propia instalación (debe coincidir EXACTAMENTE con la registrada en Google).
 *
 * @return array{client_id:string, client_secret:string, redirect_uri:string}
 */
function google_config(): array
{
    $configured = trim((string) env('GOOGLE_REDIRECT_URI', ''));
    return [
        'client_id'     => trim((string) env('GOOGLE_CLIENT_ID', '')),
        'client_secret' => trim((string) env('GOOGLE_CLIENT_SECRET', '')),
        'redirect_uri'  => $configured !== '' ? $configured : url('auth/google_callback.php'),
    ];
}

/** URL http(s) absoluta y sin usuario en ella (un `user:pass@host` es un vector de phishing). */
function google_valid_redirect_uri(string $uri): bool
{
    if ($uri === '' || preg_match('/\s/', $uri) === 1) {
        return false;
    }
    $p = parse_url($uri);
    return in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)
        && (string) ($p['host'] ?? '') !== ''
        && ($p['user'] ?? '') === ''
        && ($p['query'] ?? '') === ''
        && ($p['fragment'] ?? '') === '';
}

/** ¿Se puede ofrecer "Entrar con Google"? Sin las tres piezas la app lo esconde, no lo rompe. */
function google_configured(): bool
{
    $c = google_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '' && google_valid_redirect_uri($c['redirect_uri']);
}

/** Cliente con las credenciales del entorno. Falla ruidosa y sin secretos en el mensaje. */
function google_client(): GoogleClient
{
    $c = google_config();
    $missing = array_keys(array_filter(
        ['GOOGLE_CLIENT_ID' => $c['client_id'], 'GOOGLE_CLIENT_SECRET' => $c['client_secret']],
        static fn (string $v): bool => $v === ''
    ));
    if ($missing !== []) {
        throw new GoogleAuthException('Faltan variables de entorno de Google: ' . implode(', ', $missing));
    }
    if (!google_valid_redirect_uri($c['redirect_uri'])) {
        throw new GoogleAuthException('GOOGLE_REDIRECT_URI no es una URL http(s) válida: ' . $c['redirect_uri']);
    }
    return new GoogleClient($c['client_id'], $c['client_secret'], $c['redirect_uri']);
}

// -------------------------------------------------------------------- estado ---

/**
 * Estado anti-CSRF + verificador PKCE, guardados en la sesión.
 *
 * `issue()` genera ambos valores aleatorios (32 bytes) y `consume()` los valida una única vez
 * (los borra siempre): un `state` reutilizado, caducado o de otra sesión se descarta. Se trabaja
 * sobre el array por referencia para que la lógica sea comprobable sin abrir una sesión real.
 */
final class GoogleState
{
    /**
     * @param  array<string,mixed> $session
     * @param  string $next destino posterior (lista blanca de auth_next_path)
     * @param  string $ref  código de referido de quien trajo al usuario (?ref=)
     * @return array{state:string, verifier:string, next:string, ref:string}
     */
    public static function issue(array &$session, string $next = '', string $ref = ''): array
    {
        $data = [
            'state'    => bin2hex(random_bytes(32)),
            'verifier' => bin2hex(random_bytes(32)),
            'at'       => time(),
            'next'     => in_array($next, ['premium', 'code'], true) ? $next : '',
            'ref'      => Referrals::normalize($ref),
        ];
        $session[GOOGLE_STATE_KEY] = $data;
        return $data;
    }

    /**
     * Valida y consume el estado. null si no hay, si caducó o si no coincide.
     *
     * @param  array<string,mixed> $session
     * @return array{state:string, verifier:string, next:string, ref:string}|null
     */
    public static function consume(array &$session, string $received): ?array
    {
        $data = $session[GOOGLE_STATE_KEY] ?? null;
        unset($session[GOOGLE_STATE_KEY]);   // de un solo uso, se haya acertado o no
        if ($data === null || $received === '' || !is_array($data)) {
            return null;
        }
        $state = $data['state'] ?? null;
        $issuedAt = $data['at'] ?? null;
        $verifier = $data['verifier'] ?? null;
        if (!is_string($state) || $state === '' || !is_int($issuedAt) || !is_string($verifier) || $verifier === '') {
            return null;
        }
        if ($issuedAt < time() - GOOGLE_STATE_TTL) {
            return null;   // caducado: mejor pedir el login otra vez que aceptar un viaje viejo
        }
        if (!hash_equals($state, $received)) {
            return null;   // CSRF o viaje de otra sesión
        }
        return [
            'state'    => $state,
            'verifier' => $verifier,
            'next'     => is_string($data['next'] ?? null) ? $data['next'] : '',
            'ref'      => is_string($data['ref'] ?? null) ? $data['ref'] : '',
        ];
    }
}

// ------------------------------------------------------------------- cliente ---

final class GoogleClient
{
    private const USER_AGENT = 'LovePages/1.0 (+https://pdsx.org/love)';

    /** @var callable(string, string, list<string>, ?string): array{0:int, 1:string} */
    private $transport;

    /**
     * @param callable(string, string, list<string>, ?string): array{0:int, 1:string}|null $transport
     *        (método, url, cabeceras, cuerpo) => [status HTTP, cuerpo]. Inyectable para tests.
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $tokenUrl = GOOGLE_TOKEN_URL,
        private readonly string $userInfoUrl = GOOGLE_USERINFO_URL,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Desafío PKCE S256 de un verificador, en base64url sin relleno. */
    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * URL de autorización. `state` y `code_challenge` viajan siempre; los scopes son los mínimos.
     * No se manda `nonce`: no se valida el `id_token` (el perfil se lee por HTTPS en /userinfo),
     * así que un nonce sin verificar solo daría una falsa sensación de seguridad.
     */
    public function authorizeUrl(string $state, string $verifier, string $authUrl = GOOGLE_AUTH_URL): string
    {
        return $authUrl . '?' . http_build_query([
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', GOOGLE_SCOPES),
            'state'                 => $state,
            'code_challenge'        => self::s256($verifier),
            'code_challenge_method' => 'S256',
            'access_type'           => 'online',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Canjea el código de autorización por un access_token (con el verificador PKCE).
     *
     * @return array{access_token:string, id_token:string, expires_in:int, scope:string}
     * @throws GoogleAuthException
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        if ($code === '' || strlen($code) > 2048) {
            throw new GoogleAuthException('Google devolvió un código de autorización vacío o demasiado largo.');
        }
        [$status, $body] = ($this->transport)('POST', $this->tokenUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986));

        $data = self::decodeJson($body);
        if ($status < 200 || $status >= 300) {
            // `error_description` de Google no lleva secretos; el `code` de authorization_code no se registra.
            throw new GoogleAuthException("Google rechazó el canje del código (HTTP $status): " . self::errorText($data));
        }
        $token = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
        if ($token === '') {
            throw new GoogleAuthException("Google no devolvió access_token (HTTP $status).");
        }
        return [
            'access_token' => $token,
            'id_token'     => is_string($data['id_token'] ?? null) ? $data['id_token'] : '',
            'expires_in'   => is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 0,
            'scope'        => is_string($data['scope'] ?? null) ? $data['scope'] : '',
        ];
    }

    /**
     * Perfil del usuario autenticado. Se normaliza a tipos cerrados: `sub` (identificador estable
     * de la cuenta de Google), correo y su verificación, nombre y foto.
     *
     * @return array{sub:string, email:string, email_verified:bool, name:string, picture:string}
     * @throws GoogleAuthException
     */
    public function userInfo(string $accessToken): array
    {
        if ($accessToken === '') {
            throw new GoogleAuthException('No hay access_token para leer el perfil.');
        }
        [$status, $body] = ($this->transport)('GET', $this->userInfoUrl, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ], null);

        $data = self::decodeJson($body);
        if ($status < 200 || $status >= 300) {
            throw new GoogleAuthException("Google no devolvió el perfil del usuario (HTTP $status): " . self::errorText($data));
        }
        if (!is_array($data)) {
            throw new GoogleAuthException('Respuesta de perfil con formato inesperado.');
        }
        $sub    = is_string($data['sub'] ?? null) ? $data['sub'] : '';
        $email  = is_string($data['email'] ?? null) ? strtolower($data['email']) : '';
        $name   = is_string($data['name'] ?? null) ? $data['name'] : '';
        $pic    = is_string($data['picture'] ?? null) ? $data['picture'] : '';
        if ($sub === '' || $email === '') {
            throw new GoogleAuthException('El perfil de Google no trae `sub` o `email`.');
        }
        return [
            'sub'            => $sub,
            'email'          => $email,
            'email_verified' => ($data['email_verified'] ?? false) === true,
            'name'           => mb_substr(trim($name), 0, 120),
            'picture'        => $pic,
        ];
    }

    // ---- infraestructura --------------------------------------------------

    /**
     * Opciones de cURL de las dos peticiones. TLS se verifica SIEMPRE (nunca `false`), solo se
     * habla HTTPS, no se siguen redirecciones y hay timeouts: un 3xx jamás debe desviar el
     * Authorization ni el client_secret a otro host.
     *
     * @param  list<string> $headers
     * @return array<int,mixed>
     */
    public static function curlOptions(string $method, array $headers, ?string $body): array
    {
        return [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body ?? '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,   // solo HTTPS (igual que el cliente de Wompi)
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ];
    }

    /**
     * @param  list<string> $headers
     * @return array{0:int, 1:string}
     * @throws GoogleAuthException
     */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new GoogleAuthException('La extensión cURL de PHP no está disponible.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GoogleAuthException('No se pudo inicializar cURL para hablar con Google.');
        }
        curl_setopt_array($ch, self::curlOptions($method, $headers, $body));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new GoogleAuthException('No se pudo contactar con Google: ' . $error);
        }
        return [$status, (string) $response];
    }

    /** @return array<mixed>|null */
    private static function decodeJson(string $body): ?array
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** Texto de error de Google para el log; '' si no lo dice. Nunca contiene el client_secret. */
    private static function errorText(?array $data): string
    {
        if ($data === null) {
            return 'respuesta no JSON';
        }
        $err = is_string($data['error'] ?? null) ? $data['error'] : '';
        $desc = is_string($data['error_description'] ?? null) ? $data['error_description'] : '';
        return trim($err . ($err !== '' && $desc !== '' ? ': ' : '') . $desc) ?: 'sin detalle';
    }
}
