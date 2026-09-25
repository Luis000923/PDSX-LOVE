<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Verificación de correo por código y cliente de correo (con transporte simulado: sin sockets). */
final class EmailVerificationTest extends TestCase
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
        self::$pdo->exec('DELETE FROM email_verifications');
        $this->sent = [];
        $this->deliver = true;
        Mailer::setTransport(function (string $to, string $subject, string $text): bool {
            $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
            return $this->deliver;
        });
    }

    protected function tearDown(): void
    {
        Mailer::setTransport(null);
    }

    private function user(bool $verified = false): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, ?)')
            ->execute(['ev' . uniqid() . '@t.test', 'x', $verified ? '2026-01-01 00:00:00' : null]);
        return (int) self::$pdo->lastInsertId();
    }

    private function lastCode(): string
    {
        self::assertNotSame([], $this->sent);
        self::assertSame(1, preg_match('/: (\d{6})\b/', end($this->sent)['text'], $m));
        return $m[1];
    }

    private function isVerified(int $uid): bool
    {
        return self::$pdo->query("SELECT email_verified_at FROM users WHERE id = $uid")->fetchColumn() !== null;
    }

    public function testIssueSendsCodeAndCorrectCodeVerifies(): void
    {
        $u = $this->user();
        self::assertTrue(EmailVerification::issue($u)['ok']);
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('LovePages', $this->sent[0]['subject']);
        $code = $this->lastCode();
        self::assertFalse($this->isVerified($u));
        self::assertTrue(EmailVerification::verify($u, $code)['ok']);
        self::assertTrue($this->isVerified($u));
        self::assertSame(0, (int) self::$pdo->query("SELECT COUNT(*) FROM email_verifications WHERE user_id = $u")->fetchColumn(), 'el código se consume');
        self::assertFalse(EmailVerification::verify($u, $code)['ok'], 'no se reutiliza');
    }

    public function testCodeIsStoredHashedNotInPlain(): void
    {
        $u = $this->user();
        EmailVerification::issue($u);
        $row = self::$pdo->query("SELECT code_hash FROM email_verifications WHERE user_id = $u")->fetchColumn();
        self::assertNotSame($this->lastCode(), $row);
        self::assertSame(64, strlen((string) $row));
    }

    public function testWrongCodeCountsAttemptsAndLocksAfterFive(): void
    {
        $u = $this->user();
        EmailVerification::issue($u);
        $good = $this->lastCode();
        $bad = $good === '000000' ? '111111' : '000000';
        for ($i = 0; $i < EmailVerification::MAX_ATTEMPTS; $i++) {
            self::assertFalse(EmailVerification::verify($u, $bad)['ok']);
        }
        self::assertFalse(EmailVerification::verify($u, $good)['ok'], 'agotados los intentos, ni el bueno vale');
        self::assertFalse($this->isVerified($u));
    }

    public function testMalformedCodesAreRejected(): void
    {
        $u = $this->user();
        EmailVerification::issue($u);
        foreach (['', 'abc123', '12345', '1234567', ' '] as $c) {
            self::assertFalse(EmailVerification::verify($u, $c)['ok']);
        }
    }

    public function testExpiredCodeIsRejected(): void
    {
        $u = $this->user();
        EmailVerification::issue($u);
        $code = $this->lastCode();
        self::$pdo->exec("UPDATE email_verifications SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE user_id = $u");
        self::assertFalse(EmailVerification::verify($u, $code)['ok']);
        self::assertFalse($this->isVerified($u));
    }

    public function testResendIsRateLimitedAndReplacesTheCode(): void
    {
        $u = $this->user();
        EmailVerification::issue($u);
        $first = $this->lastCode();
        self::assertFalse(EmailVerification::issue($u)['ok'], 'reenvío inmediato bloqueado');
        self::assertCount(1, $this->sent);
        self::$pdo->exec("UPDATE email_verifications SET last_sent_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE user_id = $u");
        self::assertTrue(EmailVerification::issue($u)['ok']);
        self::assertCount(2, $this->sent);
        $second = $this->lastCode();
        if ($first !== $second) {
            self::assertFalse(EmailVerification::verify($u, $first)['ok'], 'el código anterior deja de valer');
        }
        self::assertTrue(EmailVerification::verify($u, $second)['ok']);
    }

    public function testFailedDeliveryReportsErrorAndAllowsImmediateRetry(): void
    {
        $u = $this->user();
        $this->deliver = false;
        $r = EmailVerification::issue($u);
        self::assertFalse($r['ok']);
        $this->deliver = true;
        self::assertTrue(EmailVerification::issue($u)['ok'], 'sin espera de 60 s tras un fallo de envío');
    }

    public function testAlreadyVerifiedAccountSendsNothing(): void
    {
        $u = $this->user(true);
        self::assertTrue(EmailVerification::issue($u)['ok']);
        self::assertSame([], $this->sent);
    }

    public function testUnknownUserAndIsVerifiedGate(): void
    {
        self::assertFalse(EmailVerification::issue(999999)['ok']);
        self::assertFalse(EmailVerification::isVerified(['email_verified_at' => null]), 'con SMTP activo se exige');
        self::assertTrue(EmailVerification::isVerified(['email_verified_at' => '2026-01-01 00:00:00']));
    }

    public function testMailerRejectsInvalidRecipientAndHeaderInjection(): void
    {
        Mailer::setTransport(null);
        self::assertFalse(Mailer::send('no-es-correo', 'Hola', 'x'));
        self::assertFalse(Mailer::send('a@b.co', "Hola\r\nBcc: x@y.z", 'x'));
    }

    public function testMailerIsNotConfiguredWithoutCredentials(): void
    {
        Mailer::setTransport(null);
        foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD'] as $k) {
            putenv($k);
        }
        self::assertFalse(Mailer::configured());
        self::assertFalse(Mailer::send('a@b.co', 'Hola', 'x'), 'sin SMTP no revienta: devuelve false');
        self::assertTrue(EmailVerification::isVerified(['email_verified_at' => null]), 'sin correo no se bloquea a nadie');
    }
}
