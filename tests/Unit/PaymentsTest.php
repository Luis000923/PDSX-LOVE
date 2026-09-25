<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Payments.php';

/** Cumplimiento idempotente, aprobación/anulación manual y cupones (incluido 100 %). BD `*_test`. */
final class PaymentsTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM promo_redemptions');
        self::$pdo->exec('DELETE FROM payments');
        self::$pdo->exec('DELETE FROM coin_transactions');
        self::$pdo->exec('DELETE FROM promos');
        self::$pdo->exec('DELETE FROM users');
    }

    private function user(string $email = 'a@x.test'): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, 'x']);
        return (int) self::$pdo->lastInsertId();
    }

    private function tier(string $slug): int
    {
        return (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$slug'")->fetchColumn();
    }

    private function pay(int $uid, string $status = 'PENDING', ?int $tier = null, ?int $coins = null, ?string $promo = null, int $cents = 399): int
    {
        self::$pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, status, tier_id, coins, promo_code) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, 'R-' . bin2hex(random_bytes(6)), $cents, $status, $tier, $coins, $promo]);
        return (int) self::$pdo->lastInsertId();
    }

    private function promo(string $code, int $pct, array $extra = []): array
    {
        self::$pdo->prepare('INSERT INTO promos (code, discount_percent, max_uses, scope, tier_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$code, $pct, $extra['max_uses'] ?? 0, $extra['scope'] ?? 'all', $extra['tier_id'] ?? null]);
        return (array) Admin::findUsablePromo($code);
    }

    private function coinsOf(int $uid): int
    {
        return (int) self::$pdo->query("SELECT coins FROM users WHERE id = $uid")->fetchColumn();
    }

    public function testFulfillIsIdempotent(): void
    {
        $u = $this->user();
        $t = $this->tier('pareja');
        $pid = $this->pay($u, 'APPROVED', $t);
        $a = Payments::fulfill(self::$pdo, $pid, 'test');
        $b = Payments::fulfill(self::$pdo, $pid, 'test');
        self::assertTrue($a['ok']);
        self::assertFalse($a['already']);
        self::assertTrue($b['already']);
        self::assertSame(45, $this->coinsOf($u), 'el bono se acredita una sola vez');
        self::assertSame($t, (int) self::$pdo->query("SELECT membership_tier_id FROM users WHERE id = $u")->fetchColumn());
        self::assertNotNull(self::$pdo->query("SELECT fulfilled_at FROM payments WHERE id = $pid")->fetchColumn());
    }

    public function testFulfillRefusesUnapproved(): void
    {
        $u = $this->user();
        $r = Payments::fulfill(self::$pdo, $this->pay($u, 'PENDING', $this->tier('pareja')), 'test');
        self::assertFalse($r['ok']);
        self::assertSame(0, $this->coinsOf($u));
    }

    public function testManualApprovalActivatesCoinsAndRecordsAdmin(): void
    {
        $u = $this->user();
        $adm = $this->user('adm@x.test');
        $pid = $this->pay($u, 'DECLINED', null, 500, null, 500);
        self::assertFalse(Payments::approveManually(self::$pdo, $pid, $adm, '  ')['ok'], 'la nota es obligatoria');
        $r = Payments::approveManually(self::$pdo, $pid, $adm, 'transferencia verificada');
        self::assertTrue($r['ok']);
        self::assertSame(500, $this->coinsOf($u));
        $row = self::$pdo->query("SELECT status, method, approved_by, admin_note, fulfilled_at FROM payments WHERE id = $pid")->fetch();
        self::assertSame(['APPROVED', 'MANUAL', $adm, 'transferencia verificada'], [$row['status'], $row['method'], (int) $row['approved_by'], $row['admin_note']]);
        self::assertNotNull($row['fulfilled_at']);
    }

    public function testManualApprovalActivatesTier(): void
    {
        $u = $this->user();
        $t = $this->tier('eterno');
        Payments::approveManually(self::$pdo, $this->pay($u, 'PENDING', $t), $u, 'ok');
        self::assertSame($t, (int) self::$pdo->query("SELECT membership_tier_id FROM users WHERE id = $u")->fetchColumn());
        self::assertSame(100, $this->coinsOf($u));
    }

    public function testCannotApproveTwiceNorVoidFulfilled(): void
    {
        $u = $this->user();
        $pid = $this->pay($u, 'PENDING', null, 100, null, 100);
        self::assertTrue(Payments::approveManually(self::$pdo, $pid, $u, 'ok')['ok']);
        self::assertFalse(Payments::approveManually(self::$pdo, $pid, $u, 'otra vez')['ok']);
        self::assertFalse(Payments::void(self::$pdo, $pid, $u, 'x')['ok']);
        self::assertSame(100, $this->coinsOf($u));
    }

    public function testVoidUnfulfilledThenCannotApprove(): void
    {
        $u = $this->user();
        $pid = $this->pay($u, 'PENDING', null, 100, null, 100);
        self::assertFalse(Payments::void(self::$pdo, $pid, $u, '')['ok']);
        self::assertTrue(Payments::void(self::$pdo, $pid, $u, 'duplicado')['ok']);
        self::assertSame('VOIDED', self::$pdo->query("SELECT status FROM payments WHERE id = $pid")->fetchColumn());
        self::assertFalse(Payments::approveManually(self::$pdo, $pid, $u, 'x')['ok']);
        self::assertSame(0, $this->coinsOf($u));
    }

    public function testFreeCouponCreatesZeroPaymentWithoutWompi(): void
    {
        $u = $this->user();
        $t = $this->tier('pareja');
        $promo = $this->promo('GRATIS1', 100, ['max_uses' => 1]);
        self::assertSame(0, Payments::discountedCents(399, 100));
        $r = Payments::redeemFree(self::$pdo, $u, $promo, $t, null);
        self::assertTrue($r['ok']);
        $p = self::$pdo->query('SELECT amount_in_cents, method, status, link_id, fulfilled_at FROM payments WHERE id = ' . (int) $r['payment_id'])->fetch();
        self::assertSame([0, 'PROMO', 'APPROVED', null], [(int) $p['amount_in_cents'], $p['method'], $p['status'], $p['link_id']]);
        self::assertNotNull($p['fulfilled_at']);
        self::assertSame($t, (int) self::$pdo->query("SELECT membership_tier_id FROM users WHERE id = $u")->fetchColumn());
        self::assertSame(1, (int) self::$pdo->query('SELECT uses FROM promos WHERE id = ' . (int) $promo['id'])->fetchColumn());
        // agotado para otro usuario
        self::assertFalse(Payments::redeemFree(self::$pdo, $this->user('b@x.test'), $promo, $t, null)['ok']);
    }

    public function testUserCannotReuseCoupon(): void
    {
        $u = $this->user();
        $t = $this->tier('romantico');
        $promo = $this->promo('UNAVEZ', 100);
        self::assertTrue(Payments::redeemFree(self::$pdo, $u, $promo, $t, null)['ok']);
        self::assertTrue(Payments::alreadyRedeemed(self::$pdo, (int) $promo['id'], $u));
        self::assertFalse(Payments::redeemFree(self::$pdo, $u, $promo, $t, null)['ok']);
    }

    public function testPartialCouponRedemptionRecordedOnFulfill(): void
    {
        $u = $this->user();
        $promo = $this->promo('MEDIO', 50);
        self::assertSame(200, Payments::discountedCents(399, 50));
        Payments::fulfill(self::$pdo, $this->pay($u, 'APPROVED', $this->tier('pareja'), null, 'MEDIO', 200), 'test');
        self::assertTrue(Payments::alreadyRedeemed(self::$pdo, (int) $promo['id'], $u));
    }

    /** Un cupón de un solo uso total no puede acabar canjeado por dos usuarios distintos (hallazgo de pentest). */
    public function testMaxUsesNotExceededByTwoUsersFulfillingConcurrently(): void
    {
        $a = $this->user('a@x.test');
        $b = $this->user('b@x.test');
        $promo = $this->promo('SOLOUNO', 50, ['max_uses' => 1]);
        $payA = $this->pay($a, 'APPROVED', $this->tier('romantico'), null, 'SOLOUNO', 50);
        $payB = $this->pay($b, 'APPROVED', $this->tier('romantico'), null, 'SOLOUNO', 50);

        self::assertTrue(Payments::fulfill(self::$pdo, $payA, 'test')['ok']);
        self::assertTrue(Payments::fulfill(self::$pdo, $payB, 'test')['ok']);   // el pago igual se cumple (ya se cobró)

        $row = self::$pdo->query('SELECT uses, max_uses FROM promos WHERE id = ' . (int) $promo['id'])->fetch();
        self::assertSame(1, (int) $row['uses'], 'el contador no debe superar max_uses aunque dos pagos se cumplan casi a la vez');
        $redemptions = (int) self::$pdo->query('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ' . (int) $promo['id'])->fetchColumn();
        self::assertSame(1, $redemptions, 'solo un usuario debe quedar con el canje registrado');
    }

    public function testCouponScopeLimitedToTier(): void
    {
        $pareja = $this->tier('pareja');
        $promo = $this->promo('SOLOPAREJA', 100, ['scope' => 'tiers', 'tier_id' => $pareja]);
        self::assertTrue(Payments::promoApplies($promo, $pareja, false));
        self::assertFalse(Payments::promoApplies($promo, $this->tier('eterno'), false));
        self::assertFalse(Payments::promoApplies($promo, null, true));
        $tpl = $this->promo('SOLOPLANTILLA', 10, ['scope' => 'templates']);
        self::assertTrue(Payments::promoApplies($tpl, null, true));
        self::assertFalse(Payments::promoApplies($tpl, $pareja, false));
    }

    public function testDiscountNeverNegative(): void
    {
        foreach ([[0, 50], [1, 99], [399, 100], [399, 150], [399, -5], [-10, 10]] as [$c, $p]) {
            self::assertGreaterThanOrEqual(0, Payments::discountedCents($c, $p));
        }
        self::assertSame(WOMPI_MIN_AMOUNT_IN_CENTS, Payments::discountedCents(1, 99));
    }

    public function testCheckAllows1To100Only(): void
    {
        $ins = self::$pdo->prepare('INSERT INTO promos (code, discount_percent) VALUES (?, ?)');
        $ins->execute(['CIEN', 100]);
        foreach ([0, 101] as $bad) {
            try {
                $ins->execute(['MAL' . $bad, $bad]);
                self::fail("$bad debió rechazarse");
            } catch (PDOException $e) {
                self::assertSame('HY000', $e->getCode() === 'HY000' ? 'HY000' : (string) $e->getCode());
            }
        }
        self::assertGreaterThanOrEqual(12, DB_SCHEMA_VERSION, 'la migración que ajusta el CHECK de promos (v9+) está aplicada');
    }
}
