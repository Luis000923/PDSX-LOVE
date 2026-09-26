<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Verificación de pagos por la API de Wompi (GET /EnlacePago/{id}), sin depender del webhook. Transporte simulado. */
final class WompiVerificationTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function approvedLink(float $monto = 1.0, array $over = []): array
    {
        return array_replace([
            'estaProductivo'    => false,
            'transaccionCompra' => ['esAprobada' => true, 'resultadoTransaccion' => 0, 'monto' => $monto, 'idTransaccion' => 'tx-123', 'esReal' => false],
        ], $over);
    }

    /** @param array<mixed>|null $body */
    private static function client(int $status, ?array $body, ?array &$calls = null): WompiClient
    {
        $calls = [];
        return new WompiClient('id', 'secret', 'https://tok.test/t', 'https://api.test', function (string $m, string $url, array $h, ?string $b) use ($status, $body, &$calls): array {
            $calls[] = "$m $url";
            if (str_contains($url, '/t')) {
                return [200, json_encode(['access_token' => 'tkn', 'expires_in' => 3600])];
            }
            return [$status, json_encode($body)];
        });
    }

    public function testParseAprobadoCaseInsensitive(): void
    {
        $r = WompiClient::parseLinkStatus(['TransaccionCompra' => ['EsAprobada' => true, 'ResultadoTransaccion' => 0, 'Monto' => 4.99, 'IdTransaccion' => 'abc']]);
        $this->assertTrue($r['approved']);
        $this->assertSame(499, $r['amount_cents']);
        $this->assertSame('abc', $r['transaction_id']);
    }

    public function testParseNoAprobadoOSinTransaccion(): void
    {
        $this->assertFalse(WompiClient::parseLinkStatus([])['approved']);
        $this->assertFalse(WompiClient::parseLinkStatus(['transaccionCompra' => ['esAprobada' => false, 'resultadoTransaccion' => 1]])['approved']);
        $this->assertFalse(WompiClient::parseLinkStatus(['transaccionCompra' => ['esAprobada' => 'true', 'monto' => 1]])['approved'], 'solo el booleano true cuenta');
        $this->assertFalse(WompiClient::parseLinkStatus(['transaccionCompra' => ['esAprobada' => true, 'resultadoTransaccion' => 2, 'monto' => 1]])['approved'], 'aprobada pero resultado distinto de 0');
    }

    public function testParseUsaListaDeTransacciones(): void
    {
        $r = WompiClient::parseLinkStatus(['transacciones' => [['esAprobada' => false], ['esAprobada' => true, 'monto' => 2, 'idTransaccion' => 'z']]]);
        $this->assertTrue($r['approved']);
        $this->assertSame(200, $r['amount_cents']);
    }

    public function testGetPaymentLinkPideElEnlaceConToken(): void
    {
        $calls = [];
        $data = self::client(200, self::approvedLink(), $calls)->getPaymentLink(4436404);
        $this->assertArrayHasKey('transaccionCompra', $data);
        $this->assertContains('GET https://api.test/EnlacePago/4436404', $calls);
    }

    public function testGetPaymentLinkFallaConHttpNo2xx(): void
    {
        $this->expectException(WompiException::class);
        self::client(404, ['x' => 1])->getPaymentLink(1);
    }

    // ---- reconciliación contra la BD -----------------------------------------------------------

    private static function pdo(): PDO
    {
        try {
            return db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
    }

    /** @return array{0:PDO, 1:array<string,mixed>} */
    private static function pending(int $cents = 100, string $when = 'UTC_TIMESTAMP()'): array
    {
        $pdo = self::pdo();
        $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['wv' . uniqid() . '@t.test', 'x']);
        $uid = (int) $pdo->lastInsertId();
        $tier = (int) $pdo->query('SELECT id FROM membership_tiers ORDER BY id LIMIT 1')->fetchColumn();
        $ref = 'LP-' . $uid . '-' . bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, currency, status, tier_id, link_id, created_at, updated_at)
                       VALUES (?, ?, ?, 'USD', 'PENDING', ?, 999, $when, $when)")->execute([$uid, $ref, $cents, $tier ?: null]);
        return [$pdo, ['id' => (int) $pdo->lastInsertId(), 'reference' => $ref, 'amount_in_cents' => $cents, 'link_id' => 999, 'user_id' => $uid]];
    }

    private static function estado(PDO $pdo, int $id): string
    {
        return (string) $pdo->query("SELECT status FROM payments WHERE id = $id")->fetchColumn();
    }

    public function testReconcileAprobaYAplicaUnaSolaVez(): void
    {
        [$pdo, $pay] = self::pending(100);
        $c = self::client(200, self::approvedLink(1.0));
        $this->assertSame('approved', Payments::reconcile($pdo, $pay, $c));
        $this->assertSame('APPROVED', self::estado($pdo, $pay['id']));
        $this->assertNotNull($pdo->query("SELECT fulfilled_at FROM payments WHERE id = {$pay['id']}")->fetchColumn());
        // Segunda pasada: ya no está PENDING; el efecto no se repite.
        $this->assertSame('approved', Payments::reconcile($pdo, $pay, $c));
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE id = {$pay['id']} AND fulfilled_at IS NOT NULL")->fetchColumn());
    }

    public function testReconcileNoAprobaMontoDistinto(): void
    {
        [$pdo, $pay] = self::pending(100);
        $this->assertSame('mismatch', Payments::reconcile($pdo, $pay, self::client(200, self::approvedLink(0.01))));
        $this->assertSame('PENDING', self::estado($pdo, $pay['id']));
    }

    public function testReconcileSigueEnPendienteSiWompiNoLoApruebaOFalla(): void
    {
        [$pdo, $pay] = self::pending(100);
        $this->assertSame('pending', Payments::reconcile($pdo, $pay, self::client(200, ['transaccionCompra' => null])));
        $this->assertSame('error', Payments::reconcile($pdo, $pay, self::client(500, ['e' => 1])));
        $this->assertSame('PENDING', self::estado($pdo, $pay['id']));
    }

    public function testClaimEnfriamientoDe15Segundos(): void
    {
        [$pdo, $pay] = self::pending(100);                       // recién creado: aún en enfriamiento
        $this->assertFalse(Payments::claim($pdo, $pay['id']));
        $pdo->exec("UPDATE payments SET updated_at = UTC_TIMESTAMP() - INTERVAL 30 SECOND WHERE id = {$pay['id']}");
        $this->assertTrue(Payments::claim($pdo, $pay['id']));
        $this->assertFalse(Payments::claim($pdo, $pay['id']), 'el segundo intento inmediato no puede reclamar');
    }

    public function testReconcilePendingSoloPagosRecientesDelUsuario(): void
    {
        [$pdo, $pay] = self::pending(100, 'UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $this->assertSame(1, Payments::reconcilePending($pdo, $pay['user_id'], self::client(200, self::approvedLink(1.0))));
        [$pdo, $old] = self::pending(100, 'UTC_TIMESTAMP() - INTERVAL 10 HOUR');
        $this->assertSame(0, Payments::reconcilePending($pdo, $old['user_id'], self::client(200, self::approvedLink(1.0))), 'pagos de más de 6 h no se consultan');
    }
}
