<?php
declare(strict_types=1);

/**
 * Wompi: configuración y helpers de firma.
 * Docs: https://docs.wompi.co (Web Checkout + Eventos/Webhooks)
 */
function wompi_config(): array
{
    return [
        'checkout_url'     => 'https://checkout.wompi.co/p/',
        'public_key'       => (string) env('WOMPI_PUBLIC_KEY'),
        'integrity_secret' => (string) env('WOMPI_INTEGRITY_SECRET'),
        'events_secret'    => (string) env('WOMPI_EVENTS_SECRET'),
        'currency'         => 'COP',
        // El precio del panel (settings.premium_price_cop) manda sobre el del .env.
        'price_in_cents'   => (int) (Admin::setting('premium_price_cop') ?: env('PREMIUM_PRICE_COP', '19900')) * 100,
    ];
}

/** Monto mínimo aceptado por Wompi (1.500 COP). Un descuento nunca puede bajar de aquí. */
const WOMPI_MIN_AMOUNT_IN_CENTS = 150000;

/** Firma de integridad del checkout: sha256(reference + amount + currency + integrity_secret). */
function wompi_integrity_signature(string $reference, int $amountInCents, string $currency): string
{
    return hash('sha256', $reference . $amountInCents . $currency . wompi_config()['integrity_secret']);
}

/** URL de redirección al Web Checkout de Wompi. */
function wompi_checkout_url(string $reference, int $amountInCents, string $redirectUrl): string
{
    $c = wompi_config();
    return $c['checkout_url'] . '?' . http_build_query([
        'public-key'          => $c['public_key'],
        'currency'            => $c['currency'],
        'amount-in-cents'     => $amountInCents,
        'reference'           => $reference,
        'signature:integrity' => wompi_integrity_signature($reference, $amountInCents, $c['currency']),
        'redirect-url'        => $redirectUrl,
    ]);
}

/**
 * Verifica el checksum de un evento (webhook).
 * checksum = sha256( valores de signature.properties concatenados + timestamp + events_secret )
 */
function wompi_verify_event(array $event): bool
{
    $secret = wompi_config()['events_secret'];
    if ($secret === '') {
        return false;   // sin secreto configurado el checksum sería forjable
    }
    $sig = $event['signature'] ?? null;
    if (!is_array($sig) || empty($sig['properties']) || !is_array($sig['properties'])
        || !isset($sig['checksum'], $event['timestamp'])) {
        return false;
    }

    $concat = '';
    foreach ($sig['properties'] as $path) {
        $value = $event['data'] ?? null;
        foreach (explode('.', (string) $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        if (!is_scalar($value)) {
            return false;
        }
        $concat .= $value;
    }

    $expected = hash('sha256', $concat . $event['timestamp'] . $secret);
    return hash_equals($expected, strtolower((string) $sig['checksum']));
}
