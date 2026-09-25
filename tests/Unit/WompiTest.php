<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WompiTest extends TestCase
{
    private const SECRET = 'secret_test';
    private const LINK_OK = '{"idEnlace":15,"urlEnlace":"https://lk.wompi.sv/yhDt","estaProductivo":false}';

    /** @var list<array{method:string, url:string, headers:list<string>, body:?string}> */
    private array $calls = [];
    private string $cacheFile = '';

    protected function setUp(): void
    {
        $this->calls = [];
        $this->cacheFile = sys_get_temp_dir() . '/wompi-test-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
        foreach (['WOMPI_ENV' => 'sandbox', 'WOMPI_TOKEN_URL' => null, 'WOMPI_API_URL' => null] as $k => $v) {
            putenv($v === null ? $k : "$k=$v");
        }
    }

    /**
     * Cliente con transporte simulado. $responses se consume en orden: [status, cuerpo].
     *
     * @param list<array{0:int, 1:string}> $responses
     */
    private function client(array $responses, bool $cache = false): WompiClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$responses): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return array_shift($responses) ?? [500, ''];
        };
        return new WompiClient('client_test', self::SECRET, 'https://id.example/token', 'https://api.example', $transport, $cache ? $this->cacheFile : null);
    }

    private static function token(string $t = 'tok1', int $expires = 3600): array
    {
        return [200, json_encode(['access_token' => $t, 'expires_in' => $expires, 'token_type' => 'Bearer'])];
    }

    private function link(WompiClient $c, int $cents = 499): array
    {
        return $c->createPaymentLink('LP-1-abc', $cents, 'Premium', 'Desc', 'https://x/dash', 'https://x/hook');
    }

    // ------------------------------------------------------------- dinero ---

    #[DataProvider('validPrices')]
    public function testUsdToCentsValid(string $in, int $cents): void
    {
        $this->assertSame($cents, wompi_usd_to_cents($in));
    }

    public static function validPrices(): array
    {
        return [['4.99', 499], ['5', 500], ['0.01', 1], ['4.5', 450], [' 12.30 ', 1230], ['99999.99', 9999999], ['0.29', 29]];
    }

    #[DataProvider('invalidPrices')]
    public function testUsdToCentsInvalid(string $in): void
    {
        $this->assertNull(wompi_usd_to_cents($in));
    }

    public static function invalidPrices(): array
    {
        return [[''], ['0'], ['0.00'], ['4.999'], ['-1'], ['1e3'], ['4,99'], ['abc'], ['100000'], ['$4.99']];
    }

    public function testFormatUsd(): void
    {
        $this->assertSame('4.99', wompi_format_usd(499));
        $this->assertSame('5.00', wompi_format_usd(500));
        $this->assertSame('0.01', wompi_format_usd(1));
    }

    public function testPriceFallsBackToEnvThenDefault(): void
    {
        putenv('PREMIUM_PRICE_USD=7.25');
        $this->assertSame(725, wompi_price_in_cents());
        putenv('PREMIUM_PRICE_USD=basura');
        $this->assertSame(WOMPI_DEFAULT_PRICE_IN_CENTS, wompi_price_in_cents());
        putenv('PREMIUM_PRICE_USD=4.99');
    }

    // ------------------------------------------------------------- config ---

    public function testSimulatorOverrideOnlyInSandbox(): void
    {
        putenv('WOMPI_TOKEN_URL=http://mock:9090/connect/token');
        putenv('WOMPI_API_URL=http://mock:9090/');
        $c = wompi_config();
        $this->assertSame('http://mock:9090/connect/token', $c['token_url']);
        $this->assertSame('http://mock:9090', $c['api_url']);

        putenv('WOMPI_ENV=production');
        $c = wompi_config();
        $this->assertSame('https://id.wompi.sv/connect/token', $c['token_url']);
        $this->assertSame('https://api.wompi.sv', $c['api_url']);
    }

    public function testUnknownOrMissingEnvIsProduction(): void
    {
        putenv('WOMPI_ENV=lo-que-sea');
        $this->assertSame('production', wompi_config()['env']);
        putenv('WOMPI_ENV');
        $this->assertSame('production', wompi_config()['env']);
    }

    public function testConfiguredRequiresBothCredentials(): void
    {
        $this->assertTrue(wompi_configured());
        putenv('WOMPI_API_SECRET=');
        $this->assertFalse(wompi_configured());
        putenv('WOMPI_API_SECRET=secret_test');
    }

    // -------------------------------------------------------------- token ---

    public function testTokenRequestIsClientCredentialsForm(): void
    {
        $tok = $this->client([self::token()])->accessToken();

        $this->assertSame('tok1', $tok);
        $this->assertCount(1, $this->calls);
        $this->assertSame('POST', $this->calls[0]['method']);
        $this->assertSame('https://id.example/token', $this->calls[0]['url']);
        parse_str((string) $this->calls[0]['body'], $form);
        $this->assertSame(
            ['grant_type' => 'client_credentials', 'audience' => 'wompi_api', 'client_id' => 'client_test', 'client_secret' => self::SECRET],
            $form
        );
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $this->calls[0]['headers']);
    }

    public function testTokenIsMemoizedInProcess(): void
    {
        $c = $this->client([self::token()]);
        $c->accessToken();
        $c->accessToken();
        $this->assertCount(1, $this->calls);
    }

    public function testTokenIsSharedThroughDiskCache(): void
    {
        $this->client([self::token('shared')], true)->accessToken();
        $this->assertSame('shared', $this->client([], true)->accessToken());   // otro "request": sin transporte
        $this->assertCount(1, $this->calls);
        $this->assertSame(0600, fileperms($this->cacheFile) & 0777);
    }

    public function testExpiredCachedTokenIsRenewed(): void
    {
        file_put_contents($this->cacheFile, json_encode(['token' => 'old', 'expires_at' => time() - 5]));
        $this->assertSame('new', $this->client([self::token('new')], true)->accessToken());
    }

    public function testTokenExpiringWithinMarginIsNotCached(): void
    {
        $this->client([self::token('short', 30)], true)->accessToken();   // 30 s < margen de 60 s
        $this->assertSame('next', $this->client([self::token('next')], true)->accessToken());
    }

    public function testForceRefreshSkipsCache(): void
    {
        $c = $this->client([self::token('a'), self::token('b')]);
        $this->assertSame('a', $c->accessToken());
        $this->assertSame('b', $c->accessToken(true));
    }

    #[DataProvider('badTokenResponses')]
    public function testTokenFailureThrowsWithoutLeakingSecret(int $status, string $body): void
    {
        try {
            $this->client([[$status, $body]])->accessToken();
            $this->fail('Debía lanzar WompiException');
        } catch (WompiException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public static function badTokenResponses(): array
    {
        return [[400, '{"error":"invalid_client"}'], [200, '{}'], [200, 'no-json'], [200, '{"access_token":""}'], [500, '']];
    }

    // ------------------------------------------------------ enlace de pago ---

    public function testCreatePaymentLinkSendsBearerAndServerAmount(): void
    {
        $r = $this->link($this->client([self::token(), [200, self::LINK_OK]]));

        $this->assertSame(['link_id' => 15, 'url' => 'https://lk.wompi.sv/yhDt'], $r);
        $call = $this->calls[1];
        $this->assertSame('https://api.example/EnlacePago', $call['url']);
        $this->assertContains('Authorization: Bearer tok1', $call['headers']);

        $body = json_decode((string) $call['body'], true);
        $this->assertSame('LP-1-abc', $body['identificadorEnlaceComercio']);
        $this->assertSame(4.99, $body['monto']);
        $this->assertSame('https://x/dash', $body['configuracion']['urlRedirect']);
        $this->assertSame('https://x/hook', $body['configuracion']['urlWebhook']);
        $this->assertFalse($body['configuracion']['esMontoEditable']);
        $this->assertSame(1, $body['limitesDeUso']['cantidadMaximaPagosExitosos']);
    }

    public function testAmountIsDecimalWithoutFloatDrift(): void
    {
        foreach ([[29, 0.29], [1, 0.01], [1999, 19.99], [500, 5.0]] as [$cents, $usd]) {
            $this->assertSame($usd, WompiClient::buildLinkPayload('x', $cents, 'p', 'd', 'https://r', 'https://w')['monto']);
        }
    }

    public function testMinimumAmountIsEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WompiClient::buildLinkPayload('x', 0, 'p', 'd', 'https://r', 'https://w');
    }

    public function testExpiredTokenTriggersOneRefreshAndRetry(): void
    {
        $c = $this->client([self::token('stale'), [401, ''], self::token('fresh'), [200, self::LINK_OK]]);
        $this->assertSame(15, $this->link($c)['link_id']);
        $this->assertCount(4, $this->calls);
        $this->assertContains('Authorization: Bearer fresh', $this->calls[3]['headers']);
    }

    public function testPersistent401Fails(): void
    {
        $this->expectException(WompiException::class);
        $this->link($this->client([self::token(), [401, ''], self::token('t2'), [401, '{}']]));
    }

    public function testApiErrorThrows(): void
    {
        $this->expectException(WompiException::class);
        $this->link($this->client([self::token(), [422, '{"mensaje":"monto inválido"}']]));
    }

    #[DataProvider('badLinkResponses')]
    public function testMalformedOrForeignLinkIsRejected(string $body): void
    {
        $this->expectException(WompiException::class);
        $this->link($this->client([self::token(), [200, $body]]));
    }

    public static function badLinkResponses(): array
    {
        return [
            ['{"idEnlace":"15","urlEnlace":"https://lk.wompi.sv/x"}'],          // idEnlace no entero
            ['{"idEnlace":15}'],                                                 // sin url
            ['{"idEnlace":15,"urlEnlace":"http://lk.wompi.sv/x"}'],              // http
            ['{"idEnlace":15,"urlEnlace":"https://evil.example/x"}'],            // dominio ajeno
            ['{"idEnlace":15,"urlEnlace":"https://wompi.sv.evil.example/x"}'],   // sufijo engañoso
            ['{"idEnlace":15,"urlEnlace":"https://evilwompi.sv/x"}'],            // sin punto separador
            ['[]'],
        ];
    }

    public function testIsWompiUrl(): void
    {
        $this->assertTrue(WompiClient::isWompiUrl('https://lk.wompi.sv/yhDt'));
        $this->assertTrue(WompiClient::isWompiUrl('https://wompi.sv/x'));
        $this->assertFalse(WompiClient::isWompiUrl('javascript:alert(1)'));
        $this->assertFalse(WompiClient::isWompiUrl('//lk.wompi.sv/x'));
    }

    // ------------------------------------------------------------ webhook ---

    private function webhookBody(array $over = []): string
    {
        return json_encode($over + [
            'IdCuenta' => 'a1', 'FechaTransaccion' => '2026-09-24T12:00:00', 'Monto' => 4.99,
            'ModuloUtilizado' => 'EnlacePago', 'IdTransaccion' => 'tx-123',
            'ResultadoTransaccion' => 'ExitosaAprobada', 'EsProductiva' => true,
            'EnlacePago' => ['Id' => 15, 'IdentificadorEnlaceComercio' => 'LP-1-abc', 'NombreProducto' => 'Premium'],
        ], JSON_THROW_ON_ERROR);
    }

    public function testValidWebhookSignature(): void
    {
        $body = $this->webhookBody();
        $this->assertTrue(WompiClient::verifyWebhook($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET));
    }

    public function testHashIsCompatibleWithOpenSslHexLowercase(): void
    {
        $sig = hash_hmac('sha256', 'abc', 'k');
        $this->assertSame(64, strlen($sig));
        $this->assertSame(strtolower($sig), $sig);
        $this->assertTrue(WompiClient::verifyWebhook('abc', strtoupper($sig), 'k'));   // tolera mayúsculas del emisor
    }

    public function testWrongSecretTamperedBodyOrMissingHeaderRejected(): void
    {
        $body = $this->webhookBody();
        $good = hash_hmac('sha256', $body, self::SECRET);

        $this->assertFalse(WompiClient::verifyWebhook($body, hash_hmac('sha256', $body, 'otro'), self::SECRET));
        $this->assertFalse(WompiClient::verifyWebhook($body . ' ', $good, self::SECRET));           // un espacio basta
        $this->assertFalse(WompiClient::verifyWebhook(str_replace('4.99', '0.01', $body), $good, self::SECRET));
        $this->assertFalse(WompiClient::verifyWebhook($body, '', self::SECRET));
        $this->assertFalse(WompiClient::verifyWebhook($body, 'zzz', self::SECRET));
    }

    public function testEmptySecretNeverValidates(): void
    {
        $this->assertFalse(WompiClient::verifyWebhook('x', hash_hmac('sha256', 'x', ''), ''));
    }

    public function testParseWebhook(): void
    {
        $tx = WompiClient::parseWebhook(json_decode($this->webhookBody(), true));
        $this->assertSame([
            'identifier' => 'LP-1-abc', 'result' => 'ExitosaAprobada', 'amount_cents' => 499,
            'transaction_id' => 'tx-123', 'productive' => true,
        ], $tx);
    }

    public function testParseWebhookRoundsToExactCents(): void
    {
        foreach ([[0.29, 29], [4.99, 499], [19.99, 1999], [1.1, 110]] as [$monto, $cents]) {
            $tx = WompiClient::parseWebhook(json_decode($this->webhookBody(['Monto' => $monto]), true));
            $this->assertSame($cents, $tx['amount_cents']);
        }
    }

    public function testParseWebhookProductiveMustBeStrictTrue(): void
    {
        foreach (['true', 1, 'yes', null] as $v) {
            $tx = WompiClient::parseWebhook(json_decode($this->webhookBody(['EsProductiva' => $v]), true));
            $this->assertFalse($tx['productive']);
        }
    }

    #[DataProvider('badWebhookPayloads')]
    public function testParseWebhookRejectsIncomplete(array $payload): void
    {
        $this->assertNull(WompiClient::parseWebhook($payload));
    }

    public static function badWebhookPayloads(): array
    {
        return [
            [[]],
            [['Monto' => 4.99, 'ResultadoTransaccion' => 'ExitosaAprobada']],
            [['Monto' => 4.99, 'ResultadoTransaccion' => 'ExitosaAprobada', 'EnlacePago' => []]],
            [['Monto' => 'abc', 'ResultadoTransaccion' => 'ExitosaAprobada', 'EnlacePago' => ['IdentificadorEnlaceComercio' => 'x']]],
            [['Monto' => 4.99, 'ResultadoTransaccion' => 1, 'EnlacePago' => ['IdentificadorEnlaceComercio' => 'x']]],
            [['Monto' => 4.99, 'ResultadoTransaccion' => 'ExitosaAprobada', 'EnlacePago' => ['IdentificadorEnlaceComercio' => '']]],
        ];
    }

    public function testOnlyExactApprovedResultGrantsPremium(): void
    {
        $this->assertSame('ExitosaAprobada', WOMPI_RESULT_APPROVED);
        $this->assertNotSame(WOMPI_RESULT_APPROVED, 'exitosaaprobada');
        $this->assertNotSame(WOMPI_RESULT_APPROVED, 'ExitosaAprobada ');
    }
}
