<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Flujo OAuth 2.0 de Google sin red: la redirección de autorización, el estado anti-CSRF
 * (issue/consume) y el canje del código + lectura del perfil, con el transporte inyectado.
 */
final class GoogleOAuthTest extends TestCase
{
    private const SUB = '118292290384729837421';
    private const EMAIL = 'luna@example.com';

    /** @var list<array{method:string, url:string, headers:list<string>, body:?string}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'APP_URL'] as $k) {
            putenv($k);
        }
        $_GET = [];
    }

    /**
     * Cliente con transporte simulado: $responses se consume en orden, [status HTTP, cuerpo].
     *
     * @param list<array{0:int, 1:string}> $responses
     */
    private function client(array $responses, string $redirect = 'https://pdsx.org/love/auth/google_callback.php'): GoogleClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$responses): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return array_shift($responses) ?? [500, ''];
        };
        return new GoogleClient('client_test', 'secret_test', $redirect, 'https://token.example/token', 'https://api.example/userinfo', $transport);
    }

    private static function tokenResponse(): array
    {
        return [200, json_encode(['access_token' => 'ya29.tok', 'expires_in' => 3599, 'scope' => 'openid email profile', 'id_token' => 'eyJ...'], JSON_THROW_ON_ERROR)];
    }

    private static function userInfoResponse(bool $verified = true, string $picture = 'https://lh3.googleusercontent.com/a/ACg8oc-x.jpg'): array
    {
        return [200, json_encode([
            'sub'            => self::SUB,
            'email'          => 'Luna@Example.com',
            'email_verified' => $verified,
            'name'           => 'Luna Ramírez',
            'picture'        => $picture,
        ], JSON_THROW_ON_ERROR)];
    }

    // ------------------------------------------------------------ configuración ---

    public function testNotConfiguredWithoutCredentials(): void
    {
        putenv('GOOGLE_CLIENT_ID');
        putenv('GOOGLE_CLIENT_SECRET');
        self::assertFalse(google_configured());
    }

    public function testConfiguredWithBothCredentialsAndValidRedirect(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('GOOGLE_REDIRECT_URI=https://pdsx.org/love/auth/google_callback.php');
        self::assertTrue(google_configured());
    }

    public function testRedirectUriFallsBackToTheAppsOwnCallback(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('GOOGLE_REDIRECT_URI');
        putenv('APP_URL=https://pdsx.org/love');
        self::assertSame('https://pdsx.org/love/auth/google_callback.php', google_config()['redirect_uri']);
        self::assertTrue(google_configured());
    }

    #[DataProvider('invalidRedirectUris')]
    public function testInvalidRedirectUriDisablesGoogle(string $uri): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('GOOGLE_REDIRECT_URI=' . $uri);
        self::assertFalse(google_valid_redirect_uri($uri));
        self::assertFalse(google_configured(), 'con una redirect_uri inválida no debe ofrecerse Google');
    }

    public static function invalidRedirectUris(): array
    {
        return [
            'vacía'          => [''],
            'sin esquema'    => ['pdsx.org/love/auth/google_callback.php'],
            'javascript'     => ['javascript:alert(1)'],
            'con usuario'    => ['https://user:pass@pdsx.org/cb.php'],
            'con query'      => ['https://pdsx.org/cb.php?a=1'],
            'con fragmento'  => ['https://pdsx.org/cb.php#x'],
            'con espacios'   => ['https://pdsx.org/cb .php'],
            'solo esquema'   => ['https://'],
        ];
    }

    public function testClientFactoryFailsLoudlyWithoutSecretsInTheMessage(): void
    {
        putenv('GOOGLE_CLIENT_ID');                       // falta el id...
        putenv('GOOGLE_CLIENT_SECRET=valor-secreto-1234'); // ...pero el secret sí está puesto
        putenv('GOOGLE_REDIRECT_URI=https://pdsx.org/love/auth/google_callback.php');
        try {
            google_client();
            self::fail('google_client() debería lanzar GoogleAuthException sin credenciales');
        } catch (GoogleAuthException $e) {
            self::assertStringContainsString('GOOGLE_CLIENT_ID', $e->getMessage(), 'dice qué variable falta');
            self::assertStringNotContainsString('valor-secreto-1234', $e->getMessage(), 'el secreto no aparece en el mensaje');
        }
    }

    public function testClientFactoryRejectsAnInvalidRedirectUri(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('GOOGLE_REDIRECT_URI=javascript:alert(1)');
        $this->expectException(GoogleAuthException::class);
        google_client();
    }

    // ------------------------------------------------------------------- estado ---

    public function testIssueStoresFreshRandomStateAndVerifier(): void
    {
        $session = [];
        $s = GoogleState::issue($session);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $s['state']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $s['verifier']);
        self::assertNotSame($s['state'], $s['verifier'], 'state y code_verifier no pueden ser lo mismo');
        self::assertSame($s['state'], $session[GOOGLE_STATE_KEY]['state']);

        $other = [];
        self::assertNotSame($s['state'], GoogleState::issue($other)['state'], 'cada viaje usa un state nuevo');
    }

    public function testConsumeReturnsPayloadAndIsSingleUse(): void
    {
        $session = [];
        $s = GoogleState::issue($session, 'premium', 'ABCD2345');
        $got = GoogleState::consume($session, $s['state']);
        self::assertNotNull($got);
        self::assertSame($s['verifier'], $got['verifier']);
        self::assertSame('premium', $got['next']);
        self::assertSame('ABCD2345', $got['ref']);
        self::assertNull(GoogleState::consume($session, $s['state']), 'el state es de un solo uso');
    }

    public function testConsumeRejectsWrongStateAndStillBurnsIt(): void
    {
        $session = [];
        $s = GoogleState::issue($session);
        self::assertNull(GoogleState::consume($session, str_repeat('a', 64)));
        self::assertNull(GoogleState::consume($session, $s['state']), 'tras un fallo el state no vale: ni por CSRF ni por reintento');
    }

    public function testConsumeRejectsExpiredState(): void
    {
        $session = [GOOGLE_STATE_KEY => ['state' => 'x', 'verifier' => 'v', 'at' => time() - GOOGLE_STATE_TTL - 1, 'next' => '', 'ref' => '']];
        self::assertNull(GoogleState::consume($session, 'x'));
    }

    public function testConsumeRejectsMissingOrMalformedState(): void
    {
        $empty = [];
        self::assertNull(GoogleState::consume($empty, 'lo-que-sea'));
        self::assertNull(GoogleState::consume($empty, ''), 'sin state en sesión no hay viaje legítimo');

        $broken = [GOOGLE_STATE_KEY => 'no-so-un-array'];
        self::assertNull(GoogleState::consume($broken, 'x'));
        $noVerifier = [GOOGLE_STATE_KEY => ['state' => 'x', 'at' => time()]];
        self::assertNull(GoogleState::consume($noVerifier, 'x'));
    }

    public function testIssueWhitelistsNextAndNormalizesRef(): void
    {
        $session = [];
        $s = GoogleState::issue($session, 'https://evil.example', 'no-es-un-codigo');
        self::assertSame('', $s['next'], 'una redirección arbitraria nunca se guarda');
        self::assertSame('', $s['ref']);
    }

    // -------------------------------------------------------------- redirección ---

    public function testAuthorizeUrlIsBuiltForGoogleWithPkceAndScopes(): void
    {
        $client = $this->client([]);
        $verifier = str_repeat('v', 43);
        $url = $client->authorizeUrl('st4te', $verifier);
        $parts = parse_url($url);
        self::assertSame('accounts.google.com', $parts['host'] ?? null);
        self::assertSame('/o/oauth2/v2/auth', $parts['path'] ?? null);

        $q = [];
        parse_str((string) ($parts['query'] ?? ''), $q);
        self::assertSame('client_test', $q['client_id']);
        self::assertSame('https://pdsx.org/love/auth/google_callback.php', $q['redirect_uri']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('st4te', $q['state']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame(GoogleClient::s256($verifier), $q['code_challenge']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $q['code_challenge']);

        $scopes = explode(' ', (string) $q['scope']);
        sort($scopes);
        self::assertSame(['email', 'openid', 'profile'], $scopes, 'solo los scopes mínimos');
        self::assertArrayNotHasKey('client_secret', $q, 'el secret jamás viaja en la URL de autorización');
    }

    public function testS256IsBase64UrlWithoutPadding(): void
    {
        $challenge = GoogleClient::s256('verificador-de-ejemplo');
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $challenge);
        self::assertSame(43, strlen($challenge));
    }

    // ---------------------------------------------------------------- canje ---

    public function testExchangeCodePostsFormEncodedWithVerifierAndSecret(): void
    {
        $client = $this->client([self::tokenResponse()]);
        $token = $client->exchangeCode('4/abc', 'verificador');
        self::assertSame('ya29.tok', $token['access_token']);
        self::assertSame(3599, $token['expires_in']);

        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://token.example/token', $call['url']);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $call['headers']);
        parse_str((string) $call['body'], $form);
        self::assertSame([
            'code'          => '4/abc',
            'client_id'     => 'client_test',
            'client_secret' => 'secret_test',
            'redirect_uri'  => 'https://pdsx.org/love/auth/google_callback.php',
            'grant_type'    => 'authorization_code',
            'code_verifier' => 'verificador',
        ], $form);
    }

    public function testExchangeCodeRejectsGoogleError(): void
    {
        $client = $this->client([[400, '{"error":"invalid_grant","error_description":"Bad Request"}']]);
        $this->expectException(GoogleAuthException::class);
        $this->expectExceptionMessage('invalid_grant');
        $client->exchangeCode('4/abc', 'verificador');
    }

    public function testExchangeCodeRejectsNonJsonAndMissingToken(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([[200, '<html>error de proxy</html>']])->exchangeCode('4/abc', 'v');
    }

    public function testExchangeCodeRejectsEmptyToken(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([[200, '{"token_type":"Bearer"}']])->exchangeCode('4/abc', 'v');
    }

    public function testExchangeCodeRefusesEmptyCode(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([])->exchangeCode('', 'v');
    }

    // ---------------------------------------------------------------- perfil ---

    public function testUserInfoUsesBearerAndNormalizesTypes(): void
    {
        $client = $this->client([self::userInfoResponse()]);
        $profile = $client->userInfo('ya29.tok');
        self::assertSame([
            'sub'            => self::SUB,
            'email'          => 'luna@example.com',   // en minúsculas: es la clave de vinculación
            'email_verified' => true,
            'name'           => 'Luna Ramírez',
            'picture'        => 'https://lh3.googleusercontent.com/a/ACg8oc-x.jpg',
        ], $profile);

        $call = $this->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.example/userinfo', $call['url']);
        self::assertContains('Authorization: Bearer ya29.tok', $call['headers']);
        self::assertNull($call['body']);
    }

    public function testUserInfoMarksUnverifiedEmail(): void
    {
        $profile = $this->client([self::userInfoResponse(false)])->userInfo('ya29.tok');
        self::assertFalse($profile['email_verified']);
    }

    public function testUserInfoRejectsMissingSubOrEmail(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([[200, '{"email":"luna@example.com"}']])->userInfo('ya29.tok');
    }

    public function testUserInfoRejectsExpiredToken(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([[401, '{"error":"invalid_token"}']])->userInfo('ya29.tok');
    }

    public function testUserInfoRefusesWithoutToken(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->client([])->userInfo('');
    }

    public function testTransportFailureIsNotSwallowed(): void
    {
        $boom = function (): array {
            throw new GoogleAuthException('No se pudo contactar con Google:Could not resolve host');
        };
        $client = new GoogleClient('c', 's', 'https://cb.example', 'https://token.example', 'https://api.example', $boom);
        $this->expectException(GoogleAuthException::class);
        $client->userInfo('ya29.tok');
    }

    // ------------------------------------------------------- transporte cURL ---

    public function testCurlOptionsNeverWeakenTlsOrFollowRedirects(): void
    {
        $o = GoogleClient::curlOptions('POST', ['Accept: application/json'], 'a=1');
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $o[CURLOPT_MAXREDIRS]);
        self::assertTrue($o[CURLOPT_RETURNTRANSFER]);
        self::assertFalse($o[CURLOPT_HEADER]);
        self::assertGreaterThan(0, $o[CURLOPT_TIMEOUT]);
        self::assertGreaterThan(0, $o[CURLOPT_CONNECTTIMEOUT]);

        // Solo HTTPS: nunca HTTP ni file:// hacia donde va el Authorization o el client_secret.
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
    }

    // -------------------------------------------------- botón y destinos ---

    public function testGoogleButtonIsHiddenWithoutCredentials(): void
    {
        // Sin credenciales la instalación no muestra un botón que solo puede fallar.
        self::assertSame('', google_login_button());
    }

    public function testGoogleButtonCarriesThePremiumIntentAndTheReferral(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('GOOGLE_REDIRECT_URI=https://pdsx.org/love/auth/google_callback.php');
        putenv('APP_URL=https://pdsx.org/love');
        $_GET = ['next' => 'premium', 'ref' => 'ABCD2345'];   // 8 caracteres del alfabeto de Referrals

        $html = google_login_button(auth_next_value());
        self::assertStringContainsString('Continuar con Google', $html);
        // El ?next= viaja al endpoint, que lo guarda en la sesión durante el viaje a Google.
        self::assertStringContainsString('auth/google.php?next=premium&amp;ref=ABCD2345', $html);
    }

    public function testGoogleButtonDropsAMalformedReferral(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('APP_URL=https://pdsx.org/love');
        $_GET = ['ref' => 'corto'];   // no cumple el formato: se descarta, no se propaga

        self::assertStringContainsString('auth/google.php"', google_login_button());
    }

    public function testGoogleButtonDropsAnUnknownNext(): void
    {
        putenv('GOOGLE_CLIENT_ID=client_test');
        putenv('GOOGLE_CLIENT_SECRET=secret_test');
        putenv('APP_URL=https://pdsx.org/love');
        $_GET = ['next' => 'https://sitio-malicioso.example/'];

        // Una lista blanca también en el botón: ?next= no se copia tal cual.
        self::assertStringNotContainsString('next', google_login_button(auth_next_value()));
    }
}
