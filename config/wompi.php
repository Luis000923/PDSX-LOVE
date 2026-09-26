<?php
declare(strict_types=1);

/**
 * Wompi El Salvador (panel.wompi.sv): configuración, cliente de la API y validación de webhooks.
 * Docs: https://docs.wompi.sv
 *
 * Flujo:
 *   1. POST id.wompi.sv/connect/token (client_credentials)  -> access_token (1 h, se cachea)
 *   2. POST api.wompi.sv/EnlacePago  (Bearer)                -> urlEnlace + idEnlace
 *   3. El usuario paga en urlEnlace; Wompi llama al webhook con la cabecera `wompi_hash`
 *      (HMAC-SHA256 del cuerpo crudo con el API Secret, hex minúscula).
 */

/** Moneda de Wompi SV. */
const WOMPI_CURRENCY = 'USD';

/** Monto mínimo aceptado por el Enlace de Pago ($0.01). Un descuento nunca baja de aquí. */
const WOMPI_MIN_AMOUNT_IN_CENTS = 1;

/** Único ResultadoTransaccion que activa Premium. Cualquier otro valor se ignora. */
const WOMPI_RESULT_APPROVED = 'ExitosaAprobada';

/** Precio por defecto si ni el panel ni el .env dan uno válido ($4.99). */
const WOMPI_DEFAULT_PRICE_IN_CENTS = 499;

/** Fallo controlado al hablar con Wompi. El mensaje nunca contiene credenciales. */
final class WompiException extends RuntimeException
{
}

/**
 * @return array{client_id:string, api_secret:string, env:string, currency:string, token_url:string, api_url:string}
 */
function wompi_config(): array
{
    // Solo 'sandbox' relaja controles; cualquier otro valor (o vacío) se trata como producción.
    $env = env('WOMPI_ENV') === 'sandbox' ? 'sandbox' : 'production';

    $tokenUrl = 'https://id.wompi.sv/connect/token';
    $apiUrl   = 'https://api.wompi.sv';
    // Redirigir la API a un simulador solo se permite en sandbox: en producción se ignora.
    if ($env === 'sandbox') {
        $tokenUrl = wompi_http_url(env('WOMPI_TOKEN_URL')) ?? $tokenUrl;
        $apiUrl   = wompi_http_url(env('WOMPI_API_URL')) ?? $apiUrl;
    }

    return [
        'client_id'  => (string) env('WOMPI_CLIENT_ID'),
        'api_secret' => (string) env('WOMPI_API_SECRET'),
        'env'        => $env,
        'currency'   => WOMPI_CURRENCY,
        'token_url'  => $tokenUrl,
        'api_url'    => rtrim($apiUrl, '/'),
    ];
}

/** Devuelve la URL si es http(s) válida; null en cualquier otro caso. */
function wompi_http_url(?string $url): ?string
{
    if ($url === null || $url === '' || !preg_match('#^https?://[^\s]+$#i', $url)) {
        return null;
    }
    return $url;
}

/** ¿Hay credenciales para operar? Sin ellas el checkout se desactiva y el webhook rechaza todo. */
function wompi_configured(): bool
{
    $c = wompi_config();
    return $c['client_id'] !== '' && $c['api_secret'] !== '';
}

// ------------------------------------------------------------------ dinero ---
// Todo el dinero se guarda y compara en CENTAVOS ENTEROS (nunca float); solo se convierte
// a decimal en el borde: al enviarlo a Wompi y al mostrarlo.

/**
 * "4.99" -> 499. Estricto: solo dígitos con hasta 2 decimales, entre $0.01 y $99999.99.
 */
function wompi_usd_to_cents(string $usd): ?int
{
    if (!preg_match('/^(\d{1,5})(?:\.(\d{1,2}))?$/', trim($usd), $m)) {
        return null;
    }
    $cents = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    return $cents >= WOMPI_MIN_AMOUNT_IN_CENTS ? $cents : null;
}

/** 499 -> "4.99" (texto, siempre 2 decimales). */
function wompi_format_usd(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/**
 * Precio Premium en centavos. Prioridad: panel admin > PREMIUM_PRICE_USD (.env) > $4.99.
 * Un valor inválido en una capa cae a la siguiente, nunca a 0.
 */
function wompi_price_in_cents(): int
{
    return wompi_usd_to_cents(Admin::setting('premium_price_usd'))
        ?? wompi_usd_to_cents((string) env('PREMIUM_PRICE_USD', '4.99'))
        ?? WOMPI_DEFAULT_PRICE_IN_CENTS;
}

// ----------------------------------------------------------------- cliente ---

final class WompiClient
{
    private ?string $memoToken = null;

    /** @var callable(string, string, list<string>, ?string): array{0:int, 1:string} */
    private $transport;

    /**
     * @param callable(string, string, list<string>, ?string): array{0:int, 1:string}|null $transport
     *        (método, url, cabeceras, cuerpo) => [status HTTP, cuerpo]. Inyectable para tests.
     * @param string|null $cacheFile archivo donde cachear el token; null = sin caché en disco.
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $apiSecret,
        private readonly string $tokenUrl = 'https://id.wompi.sv/connect/token',
        private readonly string $apiUrl = 'https://api.wompi.sv',
        ?callable $transport = null,
        private readonly ?string $cacheFile = null,
    ) {
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Cliente construido desde el entorno, con caché de token en el directorio temporal. */
    public static function fromEnv(): self
    {
        $c = wompi_config();
        $cache = sys_get_temp_dir() . '/lovepages-wompi-' . substr(hash('sha256', $c['client_id'] . $c['token_url']), 0, 16) . '.json';
        return new self($c['client_id'], $c['api_secret'], $c['token_url'], $c['api_url'], null, $cache);
    }

    /**
     * Token OAuth (client_credentials). Se reutiliza hasta 60 s antes de expirar.
     *
     * @throws WompiException
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = $this->memoToken ?? $this->readCache();
            if ($cached !== null) {
                return $this->memoToken = $cached;
            }
        }

        [$status, $body] = ($this->transport)('POST', $this->tokenUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query([
            'grant_type'    => 'client_credentials',
            'audience'      => 'wompi_api',
            'client_id'     => $this->clientId,
            'client_secret' => $this->apiSecret,
        ]));

        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new WompiException("Wompi rechazó la autenticación (HTTP $status).");
        }

        $expiresIn = is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 3600;
        $this->writeCache($data['access_token'], time() + max(0, $expiresIn - 60));
        return $this->memoToken = $data['access_token'];
    }

    /**
     * Crea un Enlace de Pago de un solo uso.
     *
     * @param string $identifier   identificadorEnlaceComercio: nuestra referencia única (vuelve en el webhook)
     * @param int    $amountCents  monto en centavos de USD, fijado por el servidor
     * @return array{link_id:int, url:string}
     * @throws WompiException
     */
    public function createPaymentLink(
        string $identifier,
        int $amountCents,
        string $productName,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
    ): array {
        $payload = self::buildLinkPayload($identifier, $amountCents, $productName, $description, $redirectUrl, $webhookUrl);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $send = fn (string $token): array => ($this->transport)('POST', $this->apiUrl . '/EnlacePago', [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $json);

        [$status, $body] = $send($this->accessToken());
        if ($status === 401) {                      // token revocado o caducado antes de tiempo
            [$status, $body] = $send($this->accessToken(true));
        }

        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new WompiException("Wompi no creó el enlace de pago (HTTP $status): " . mb_substr($body, 0, 300));
        }

        $linkId = $data['idEnlace'] ?? null;
        $url    = $data['urlEnlace'] ?? null;
        if (!is_int($linkId) || !is_string($url) || !self::isWompiUrl($url)) {
            throw new WompiException('Respuesta de Wompi sin idEnlace/urlEnlace válidos.');
        }
        return ['link_id' => $linkId, 'url' => $url];
    }

    /**
     * Cuerpo de POST /EnlacePago. Solo tarjeta; enlace de un único pago exitoso; monto y cantidad no editables.
     *
     * @return array<string, mixed>
     */
    public static function buildLinkPayload(
        string $identifier,
        int $amountCents,
        string $productName,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
    ): array {
        if ($amountCents < WOMPI_MIN_AMOUNT_IN_CENTS) {
            throw new InvalidArgumentException('El monto mínimo es $0.01.');
        }
        return [
            'identificadorEnlaceComercio' => $identifier,
            'monto'                       => round($amountCents / 100, 2),
            'nombreProducto'              => $productName,
            'formaPago' => [
                'permitirTarjetaCreditoDebido' => true,
                'permitirPagoConPuntoAgricola' => false,
                'permitirPagoEnCuotasAgricola' => false,
                'permitirPagoEnBitcoin'        => false,
                'permitePagoQuickPay'          => false,
            ],
            'infoProducto'  => ['descripcionProducto' => $description],
            'configuracion' => [
                'urlRedirect'                 => $redirectUrl,
                'urlWebhook'                  => $webhookUrl,
                'esMontoEditable'             => false,
                'esCantidadEditable'          => false,
                'cantidadPorDefecto'          => 1,
                'notificarTransaccionCliente' => true,
            ],
            'limitesDeUso' => ['cantidadMaximaPagosExitosos' => 1],
        ];
    }

    /** Solo se redirige al usuario a dominios de Wompi (https://*.wompi.sv), nunca a lo que diga la respuesta. */
    public static function isWompiUrl(string $url): bool
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        return ($p['scheme'] ?? '') === 'https'
            && ($host === 'wompi.sv' || str_ends_with($host, '.wompi.sv'));
    }

    // ---- verificación por API (no depende del webhook) -------------------

    /**
     * GET /EnlacePago/{id}: estado real del enlace y de su transacción. Es la fuente de verdad para
     * confirmar un pago aunque el webhook no llegue o llegue sin firma verificable.
     *
     * @return array<mixed>
     * @throws WompiException
     */
    public function getPaymentLink(int $linkId): array
    {
        $send = fn (string $token): array => ($this->transport)('GET', $this->apiUrl . '/EnlacePago/' . $linkId, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], null);

        [$status, $body] = $send($this->accessToken());
        if ($status === 401) {
            [$status, $body] = $send($this->accessToken(true));
        }
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new WompiException("Wompi no devolvió el enlace $linkId (HTTP $status).");
        }
        return $data;
    }

    /**
     * Interpreta la respuesta de GET /EnlacePago/{id}. Claves sin distinguir mayúsculas (la API mezcla
     * camelCase y PascalCase). Aprobado = transaccionCompra.esAprobada === true y resultado 0 (aprobada).
     *
     * @param  array<mixed> $link
     * @return array{approved:bool, amount_cents:?int, transaction_id:string, productive:bool}
     */
    public static function parseLinkStatus(array $link): array
    {
        $tx = self::pick($link, 'transaccionCompra');
        if (!is_array($tx) || self::pick($tx, 'esAprobada') !== true) {
            $tx = null;
            $all = self::pick($link, 'transacciones');
            foreach (is_array($all) ? $all : [] as $cand) {
                if (is_array($cand) && self::pick($cand, 'esAprobada') === true) {
                    $tx = $cand;
                    break;
                }
            }
        }
        $none = ['approved' => false, 'amount_cents' => null, 'transaction_id' => '', 'productive' => false];
        if ($tx === null) {
            return $none;
        }
        $result = self::pick($tx, 'resultadoTransaccion');
        if ($result !== null && $result !== 0 && $result !== '0' && $result !== 'ExitosaAprobada') {
            return $none;   // esAprobada sin resultado 0: ante la duda, no se concede nada
        }
        $amount = self::pick($tx, 'monto');
        $id = self::pick($tx, 'idTransaccion');
        $productive = self::pick($link, 'estaProductivo') === true || self::pick($tx, 'esReal') === true;
        return [
            'approved'       => true,
            'amount_cents'   => is_numeric($amount) ? (int) round(((float) $amount) * 100) : null,
            'transaction_id' => is_string($id) ? $id : '',
            'productive'     => $productive,
        ];
    }

    /**
     * Resumen SIN datos personales de la respuesta de GET /EnlacePago/{id}, para el log de diagnóstico
     * (claves, banderas y, por transacción, aprobada/resultado/monto/productiva). Nunca el cuerpo completo.
     *
     * @param array<mixed> $link
     */
    public static function describeLink(array $link): string
    {
        $bool = static fn (mixed $v): string => $v === true ? 'true' : ($v === false ? 'false' : '-');
        $tx = static function (mixed $t) use ($bool): string {
            if (!is_array($t)) {
                return 'null';
            }
            $res = self::pick($t, 'resultadoTransaccion');
            $mon = self::pick($t, 'monto');
            return 'aprobada=' . $bool(self::pick($t, 'esAprobada')) . ',resultado=' . (is_scalar($res) ? (string) $res : '-')
                . ',monto=' . (is_numeric($mon) ? (string) $mon : '-') . ',real=' . $bool(self::pick($t, 'esReal'));
        };
        $list = self::pick($link, 'transacciones');
        $n = self::pick($link, 'cantidadPagosExitosos');
        return '[claves=' . implode('|', array_map('strval', array_keys($link))) . '; transaccionCompra=' . $tx(self::pick($link, 'transaccionCompra'))
            . '; transacciones=' . (is_array($list) ? count($list) : '-') . '; exitosos=' . (is_scalar($n) ? (string) $n : '-')
            . '; usable=' . $bool(self::pick($link, 'usable')) . '; productivo=' . $bool(self::pick($link, 'estaProductivo')) . ']';
    }

    /** @param array<mixed> $a */
    private static function pick(array $a, string $key): mixed
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && strcasecmp($k, $key) === 0) {
                return $v;
            }
        }
        return null;
    }

    // ---- webhook ---------------------------------------------------------

    /**
     * Valida la cabecera `wompi_hash`: HMAC-SHA256 hex minúscula del cuerpo CRUDO (sin reformatear)
     * con el API Secret como clave. Comparación en tiempo constante.
     */
    public static function verifyWebhook(string $rawBody, string $hashHeader, string $apiSecret): bool
    {
        if ($apiSecret === '' || $hashHeader === '') {
            return false;   // sin secreto configurado la firma sería forjable
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $apiSecret), strtolower(trim($hashHeader)));
    }

    /**
     * Extrae del payload del webhook solo lo que usamos, ya tipado. Null si falta algo esencial.
     *
     * @param  array<mixed> $payload
     * @return array{identifier:string, result:string, amount_cents:int, transaction_id:string, productive:bool}|null
     */
    public static function parseWebhook(array $payload): ?array
    {
        $link = $payload['EnlacePago'] ?? null;
        $identifier = is_array($link) ? ($link['IdentificadorEnlaceComercio'] ?? null) : null;
        $amount = $payload['Monto'] ?? null;
        $result = $payload['ResultadoTransaccion'] ?? null;

        if (!is_string($identifier) || $identifier === '' || !is_string($result) || !is_numeric($amount)) {
            return null;
        }
        return [
            'identifier'     => $identifier,
            'result'         => $result,
            'amount_cents'   => (int) round(((float) $amount) * 100),
            'transaction_id' => is_string($payload['IdTransaccion'] ?? null) ? $payload['IdTransaccion'] : '',
            'productive'     => ($payload['EsProductiva'] ?? false) === true,
        ];
    }

    // ---- infraestructura --------------------------------------------------

    /**
     * @param  list<string> $headers
     * @return array{0:int, 1:string}
     */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new WompiException('La extensión cURL de PHP no está disponible.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body ?? '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,           // un 3xx nunca debe desviar las credenciales
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new WompiException('No se pudo contactar con Wompi: ' . $error);
        }
        return [$status, (string) $response];
    }

    private function readCache(): ?string
    {
        if ($this->cacheFile === null || !is_readable($this->cacheFile)) {
            return null;
        }
        $c = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (is_array($c) && is_string($c['token'] ?? null) && is_int($c['expires_at'] ?? null) && $c['expires_at'] > time()) {
            return $c['token'];
        }
        return null;
    }

    private function writeCache(string $token, int $expiresAt): void
    {
        if ($this->cacheFile === null) {
            return;
        }
        // Escritura atómica y solo legible por el usuario web; si falla, se pide token cada vez (no es fatal).
        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4));
        $old = umask(0177);
        $ok = @file_put_contents($tmp, json_encode(['token' => $token, 'expires_at' => $expiresAt]));
        umask($old);
        if ($ok === false || !@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
        }
    }
}
