<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Celebración y correo de compra: solo con pagos APPROVED y aplicados en la BD; nada que el cliente controle. */
final class PurchaseNoticeTest extends TestCase
{
    private static PDO $pdo;
    /** @var list<array{to:string,subject:string,text:string}> */
    private array $sent = [];
    private bool $deliver = true;

    protected function setUp(): void
    {
        try {
            self::$pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
        $this->sent = [];
        $this->deliver = true;
        Mailer::setTransport(function (string $to, string $subject, string $text): bool {
            $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
            return $this->deliver;
        });
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Mailer::setTransport(null);
        $_GET = [];
    }

    private function user(): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['pn' . uniqid() . '@t.test', 'x']);
        return (int) self::$pdo->lastInsertId();
    }

    private function payment(int $uid, string $status = 'APPROVED', bool $fulfilled = true, string $age = '1 MINUTE', ?int $coins = 50): int
    {
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, currency, status, coins, link_id, fulfilled_at)
                             VALUES (?, ?, 100, 'USD', ?, ?, 1, " . ($fulfilled ? "UTC_TIMESTAMP() - INTERVAL $age" : 'NULL') . ')')
            ->execute([$uid, 'LP-' . $uid . '-' . bin2hex(random_bytes(8)), $status, $coins]);
        return (int) self::$pdo->lastInsertId();
    }

    public function testNoSeMuestraConPagoPendienteORechazado(): void
    {
        $u = $this->user();
        $this->payment($u, 'PENDING', false);
        $this->payment($u, 'DECLINED', false);
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $u));
    }

    public function testParametrosDeUrlNoLaProvocan(): void
    {
        $u = $this->user();
        $_GET = ['pago' => 'ok', 'success' => '1', 'status' => 'APPROVED'];
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $u), 'sin pago aprobado en la BD no hay celebración, diga lo que diga la URL');
        $this->payment($u, 'APPROVED', false);   // aprobado pero sin aplicar (fulfilled_at NULL)
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $u));
    }

    public function testSeMuestraUnaSolaVez(): void
    {
        $u = $this->user();
        $this->payment($u);
        $html = PurchaseNotice::celebration(self::$pdo, $u);
        $this->assertStringContainsString('id="lp-cel"', $html);
        $this->assertStringContainsString('50 monedas', $html);
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $u), 'recargar no la repite');
    }

    public function testNoCelebraPagoDeOtroUsuarioNiPagosViejos(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->payment($a);
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $b));
        $c = $this->user();
        $this->payment($c, 'APPROVED', true, '2 HOUR');
        $this->assertSame('', PurchaseNotice::celebration(self::$pdo, $c), 'fuera de la ventana de 10 min');
    }

    public function testCorreoASoporteYCompradorUnaSolaVez(): void
    {
        putenv('MAIL_SUPPORT_EMAIL=soporte@pdsx.test');
        $u = $this->user();
        $id = $this->payment($u);
        PurchaseNotice::email(self::$pdo, $id);
        PurchaseNotice::email(self::$pdo, $id);   // idempotente
        $this->assertCount(2, $this->sent);
        $this->assertSame('soporte@pdsx.test', $this->sent[0]['to']);
        $this->assertStringContainsString('Compra confirmada', $this->sent[0]['subject']);
        $this->assertStringContainsString('50 monedas', $this->sent[0]['text']);
        $this->assertNotSame('soporte@pdsx.test', $this->sent[1]['to']);
        putenv('MAIL_SUPPORT_EMAIL');
    }

    public function testNoEnviaCorreoSiElPagoNoEstaAplicado(): void
    {
        putenv('MAIL_SUPPORT_EMAIL=soporte@pdsx.test');
        $u = $this->user();
        PurchaseNotice::email(self::$pdo, $this->payment($u, 'PENDING', false));
        PurchaseNotice::email(self::$pdo, $this->payment($u, 'APPROVED', false));
        $this->assertCount(0, $this->sent);
        putenv('MAIL_SUPPORT_EMAIL');
    }

    public function testSiElCorreoFallaSeReintenta(): void
    {
        putenv('MAIL_SUPPORT_EMAIL=soporte@pdsx.test');
        $u = $this->user();
        $id = $this->payment($u);
        $this->deliver = false;
        PurchaseNotice::email(self::$pdo, $id);
        $this->assertNull(self::$pdo->query("SELECT notified_at FROM payments WHERE id = $id")->fetchColumn(), 'sigue pendiente de notificar');
        $this->deliver = true;
        $this->sent = [];
        PurchaseNotice::retryEmails(self::$pdo, $u);
        $this->assertCount(2, $this->sent);
        putenv('MAIL_SUPPORT_EMAIL');
    }
}
