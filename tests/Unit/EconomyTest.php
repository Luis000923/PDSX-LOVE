<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Economía de monedas, caducidad de páginas y migración de planes. Corre después de DatabaseTest
 * (orden alfabético), que recrea el esquema.
 * Usa la BD `*_test` (tests/bootstrap.php); se omite si MySQL no está disponible.
 */
final class EconomyTest extends TestCase
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
        self::$pdo->exec('DELETE FROM payments');
        self::$pdo->exec('DELETE FROM users');   // cascada: páginas y movimientos
    }

    /** @return array<string,mixed> */
    private function user(string $email, ?string $tierSlug = null, int $coins = 0): array
    {
        $tierId = $tierSlug === null ? null : (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$tierSlug'")->fetchColumn();
        self::$pdo->prepare('INSERT INTO users (email, password_hash, membership_tier_id, is_premium, coins) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, 'x', $tierId, $tierId === null ? 0 : 1, $coins]);
        $id = (int) self::$pdo->lastInsertId();
        return (array) self::$pdo->query("SELECT * FROM users WHERE id = $id")->fetch();
    }

    /** @return array<string,mixed> */
    private function tpl(string $slug): array
    {
        return (array) self::$pdo->query("SELECT * FROM templates WHERE slug = '$slug'")->fetch();
    }

    private const DATA = ['your_name' => 'A', 'partner_name' => 'B', 'start_date' => '2024-01-01', 'message' => 'hola'];

    // ---- recargas ----

    /** @return list<array{int, ?string, int}> */
    public static function packs(): array
    {
        return [
            [100, null, 10], [300, null, 34], [500, null, 60], [1000, null, 125],
            [100, 'romantico', 10], [300, 'romantico', 34], [500, 'romantico', 60], [1000, 'romantico', 125],
            [100, 'pareja', 11], [300, 'pareja', 37], [500, 'pareja', 65], [1000, 'pareja', 135],
            [100, 'eterno', 12], [300, 'eterno', 40], [500, 'eterno', 70], [1000, 'eterno', 145],
        ];
    }

    #[DataProvider('packs')]
    public function testPackCoinsIncludeTierBonus(int $cents, ?string $slug, int $expected): void
    {
        $tier = $slug === null ? null : (array) self::$pdo->query("SELECT * FROM membership_tiers WHERE slug = '$slug'")->fetch();
        self::assertSame($expected, Coins::packCoins($cents, $tier));
    }

    /** Coherencia: pagar más nunca da menos monedas por dólar, con o sin membresía. */
    public function testBiggerPacksAlwaysGiveMoreCoinsPerDollar(): void
    {
        foreach ([null, 'romantico', 'pareja', 'eterno'] as $slug) {
            $tier = $slug === null ? null : (array) self::$pdo->query("SELECT * FROM membership_tiers WHERE slug = '$slug'")->fetch();
            $prev = 0.0;
            foreach (Coins::PACKS_CENTS as $cents) {
                $perUsd = Coins::packCoins($cents, $tier) / ($cents / 100);
                self::assertGreaterThan($prev, $perUsd, "$slug \$" . $cents / 100);
                $prev = $perUsd;
            }
        }
        // Un plan superior tampoco da menos que uno inferior en el mismo paquete.
        foreach (Coins::PACKS_CENTS as $cents) {
            $t = static fn(string $s): array => (array) self::$pdo->query("SELECT * FROM membership_tiers WHERE slug = '$s'")->fetch();
            self::assertLessThanOrEqual(Coins::packCoins($cents, $t('pareja')), Coins::packCoins($cents, $t('romantico')));
            self::assertLessThanOrEqual(Coins::packCoins($cents, $t('eterno')), Coins::packCoins($cents, $t('pareja')));
        }
        self::assertSame(15, Coins::totalBonusPct(300, null));
        self::assertSame(35, Coins::totalBonusPct(500, ['topup_bonus_pct' => 15]), 'el % del plan se suma al del paquete');
    }

    public function testOnlyFixedPacksAreAccepted(): void
    {
        foreach ([100, 300, 500, 1000] as $c) {
            self::assertTrue(Coins::isPack($c));
        }
        foreach ([0, 1, 99, 200, 1001, -100] as $c) {
            self::assertFalse(Coins::isPack($c));
        }
    }

    // ---- saldo ----

    public function testSpendIsAtomicAndNeverGoesNegative(): void
    {
        $u = $this->user('c1@example.com', null, 10);
        $uid = (int) $u['id'];
        self::assertFalse(Coins::spend($uid, 11, 'site_create'));
        self::assertSame(10, Coins::balance($uid));
        self::assertTrue(Coins::spend($uid, 10, 'site_create', 'abc'));
        self::assertSame(0, Coins::balance($uid));
        self::assertTrue(Coins::spend($uid, 0, 'site_create'), 'gastar 0 siempre pasa');

        Coins::credit($uid, 25, 'topup', 'LP-1');
        self::assertSame(25, Coins::balance($uid));
        $hist = Coins::history($uid);
        self::assertSame([25, -10], array_map('intval', array_column($hist, 'delta')));
        self::assertSame([25, 0], array_map('intval', array_column($hist, 'balance_after')));
    }

    public function testCheckConstraintBlocksNegativeBalance(): void
    {
        $u = $this->user('c2@example.com');
        $this->expectException(PDOException::class);
        self::$pdo->exec('UPDATE users SET coins = -1 WHERE id = ' . (int) $u['id']);
    }

    public function testCreditRejectsNonPositive(): void
    {
        $u = $this->user('c3@example.com');
        $this->expectException(InvalidArgumentException::class);
        Coins::credit((int) $u['id'], 0, 'topup');
    }

    // ---- caducidad ----

    public function testExpiryDaysFollowThePlan(): void
    {
        $now = 1_800_000_000;
        self::assertSame(gmdate('Y-m-d H:i:s', $now + 3 * 86400), Access::expiresAt($this->user('e0@example.com'), $now));
        self::assertSame(gmdate('Y-m-d H:i:s', $now + 3 * 86400), Access::expiresAt($this->user('e1@example.com', 'romantico'), $now));
        self::assertSame(gmdate('Y-m-d H:i:s', $now + 7 * 86400), Access::expiresAt($this->user('e2@example.com', 'pareja'), $now));
        self::assertSame(gmdate('Y-m-d H:i:s', $now + 14 * 86400), Access::expiresAt($this->user('e3@example.com', 'eterno'), $now));
    }

    public function testIsExpired(): void
    {
        $now = 1_800_000_000;
        $at = gmdate('Y-m-d H:i:s', $now);
        self::assertFalse(Access::isExpired(null, $now), 'NULL = sin caducidad');
        self::assertFalse(Access::isExpired($at, $now), 'en el instante exacto aún vale');
        self::assertTrue(Access::isExpired($at, $now + 1));
        self::assertFalse(Access::isExpired($at, $now - 1));
    }

    // ---- creación de páginas ----

    public function testCreateChargesCoinsAndSetsExpiry(): void
    {
        $u = $this->user('s1@example.com', 'pareja', 10);
        $res = Sites::create($u, $this->tpl('premium-heart'), self::DATA);   // cuesta 5
        self::assertArrayHasKey('slug', $res);
        self::assertSame(5, Coins::balance((int) $u['id']));

        $exp = (string) self::$pdo->query("SELECT expires_at FROM user_sites WHERE slug = '{$res['slug']}'")->fetchColumn();
        $days = (strtotime($exp . ' UTC') - time()) / 86400;
        self::assertEqualsWithDelta(7, $days, 0.01);
    }

    public function testCreateFailsWithoutCoinsAndLeavesNoTrace(): void
    {
        $u = $this->user('s2@example.com', 'pareja', 4);
        $res = Sites::create($u, $this->tpl('premium-heart'), self::DATA);
        self::assertArrayHasKey('error', $res);
        self::assertSame(4, Coins::balance((int) $u['id']));
        self::assertSame(0, Access::siteCount((int) $u['id']), 'la transacción revierte la página');
    }

    public function testFreeTemplateNeedsNoCoins(): void
    {
        $u = $this->user('s3@example.com');
        self::assertArrayHasKey('slug', Sites::create($u, $this->tpl('free-minimal'), self::DATA));
        self::assertSame(0, Coins::balance((int) $u['id']));
    }

    public function testPurchasedTemplateIsIncludedNoCoinsCharged(): void
    {
        $u = $this->user('s4@example.com', 'pareja', 0);
        $tpl = $this->tpl('premium-heart');
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, template_id) VALUES (?, 'own-1', 250, 'APPROVED', ?)")
            ->execute([$u['id'], $tpl['id']]);
        self::assertSame(0, Access::coinCost($u, $tpl));
        self::assertArrayHasKey('slug', Sites::create($u, $tpl, self::DATA));
    }

    public function testSiteLimitCountsOnlyActivePages(): void
    {
        $u = $this->user('s5@example.com', 'romantico');   // límite 2
        $tpl = $this->tpl('free-minimal');
        $a = Sites::create($u, $tpl, self::DATA);
        Sites::create($u, $tpl, self::DATA);
        self::assertArrayHasKey('error', Sites::create($u, $tpl, self::DATA), 'tercera: límite');

        self::$pdo->exec("UPDATE user_sites SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE slug = '{$a['slug']}'");
        self::assertSame(1, Access::siteCount((int) $u['id']), 'la caducada libera cupo');
        self::assertArrayHasKey('slug', Sites::create($u, $tpl, self::DATA));
    }

    // ---- renovación ----

    public function testRenewChargesAndResetsExpiry(): void
    {
        $u = $this->user('r1@example.com', 'eterno', 5);
        $res = Sites::create($u, $this->tpl('premium-heart'), self::DATA);   // -5 => 0
        self::$pdo->exec("UPDATE user_sites SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE slug = '{$res['slug']}'");
        $siteId = (int) self::$pdo->query("SELECT id FROM user_sites WHERE slug = '{$res['slug']}'")->fetchColumn();

        self::assertSame('No tienes monedas suficientes para renovar. Recarga y vuelve a intentarlo.', Sites::renew($u, $siteId));
        Coins::credit((int) $u['id'], 5, 'topup');
        self::assertNull(Sites::renew($u, $siteId));
        self::assertSame(0, Coins::balance((int) $u['id']));
        $exp = (string) self::$pdo->query("SELECT expires_at FROM user_sites WHERE id = $siteId")->fetchColumn();
        self::assertEqualsWithDelta(14, (strtotime($exp . ' UTC') - time()) / 86400, 0.01);
    }

    public function testCannotRenewSomeoneElsesSite(): void
    {
        $a = $this->user('r2@example.com');
        $b = $this->user('r3@example.com', null, 100);
        $res = Sites::create($a, $this->tpl('free-minimal'), self::DATA);
        $siteId = (int) self::$pdo->query("SELECT id FROM user_sites WHERE slug = '{$res['slug']}'")->fetchColumn();
        self::assertSame('Página no encontrada.', Sites::renew($b, $siteId));
        self::assertSame(100, Coins::balance((int) $b['id']));
    }

    // ---- migración de planes ----

    public function testTierMigrationRenamesLegacyPlansKeepingIds(): void
    {
        $pdo = self::$pdo;
        $id = (int) $pdo->query("SELECT id FROM membership_tiers WHERE slug = 'pareja'")->fetchColumn();
        $pdo->exec("UPDATE membership_tiers SET slug = 'plata', name = 'Plata' WHERE id = $id");
        $pdo->exec("DELETE FROM schema_version");
        db_migrate($pdo);
        self::assertSame($id, (int) $pdo->query("SELECT id FROM membership_tiers WHERE slug = 'pareja'")->fetchColumn(), 'mismo id, los usuarios conservan su plan');
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM membership_tiers')->fetchColumn(), 'sin duplicados');
        self::assertSame('Pareja', $pdo->query("SELECT name FROM membership_tiers WHERE id = $id")->fetchColumn());
    }

    public function testMigrationAddsPriceCoinsBeforeSeedingTemplates(): void
    {
        $pdo = self::$pdo;
        // Base previa a la v6: sin price_coins (regresión: el seed de schema.sql fallaba con "Unknown column").
        $pdo->exec('ALTER TABLE templates DROP CHECK chk_templates_price_coins');
        $pdo->exec('ALTER TABLE templates DROP COLUMN price_coins');
        $pdo->exec('DELETE FROM schema_version');
        db_migrate($pdo);
        self::assertTrue(db_column_exists($pdo, 'templates', 'price_coins'));
        self::assertSame(DB_SCHEMA_VERSION, db_schema_version($pdo));
        $this->expectException(PDOException::class);   // el CHECK quedó restaurado
        $pdo->exec("UPDATE templates SET price_coins = -1 WHERE slug = 'free-minimal'");
    }
}
