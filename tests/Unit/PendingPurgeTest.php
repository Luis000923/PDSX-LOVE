<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Los pagos WOMPI PENDING sin completar se borran pasado el TTL; el resto del historial no se toca. */
final class PendingPurgeTest extends TestCase
{
    private static PDO $pdo;

    protected function setUp(): void
    {
        try {
            self::$pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
    }

    private function user(): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['pp' . uniqid() . '@t.test', 'x']);
        return (int) self::$pdo->lastInsertId();
    }

    private function pay(int $uid, string $status, string $age): int
    {
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, currency, status, created_at)
                             VALUES (?, ?, 100, 'USD', ?, UTC_TIMESTAMP() - INTERVAL $age)")
            ->execute([$uid, 'LP-' . $uid . '-' . bin2hex(random_bytes(8)), $status]);
        return (int) self::$pdo->lastInsertId();
    }

    public function testBorraSoloPendientesVencidos(): void
    {
        $u = $this->user();
        $old = $this->pay($u, 'PENDING', '3 HOUR');
        $fresh = $this->pay($u, 'PENDING', '1 MINUTE');
        $paid = $this->pay($u, 'APPROVED', '3 HOUR');
        $declined = $this->pay($u, 'DECLINED', '3 HOUR');

        $this->assertGreaterThanOrEqual(1, Payments::purgeExpiredPending(self::$pdo, 60));
        $ids = array_map('intval', self::$pdo->query("SELECT id FROM payments WHERE user_id = $u")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertNotContains($old, $ids);
        $this->assertContains($fresh, $ids);
        $this->assertContains($paid, $ids);
        $this->assertContains($declined, $ids);
    }
}
