<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Precios por plantilla, acceso por membresía/compra y operaciones de perfil.
 * Usa la BD `*_test` (tests/bootstrap.php); se omite si MySQL no está disponible.
 * Corre después de DatabaseTest (orden alfabético), que deja el esquema recién creado.
 */
final class MembershipTest extends TestCase
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
        self::$pdo->exec('DELETE FROM users');
    }

    private function user(string $email, string $pass = 'clave-vieja-1', int $premium = 0): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, is_premium) VALUES (?, ?, ?)')
            ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $premium]);
        return (int) self::$pdo->lastInsertId();
    }

    private function pay(int $uid, ?int $tplId, string $status, string $ref): void
    {
        self::$pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, status, template_id) VALUES (?, ?, 250, ?, ?)')
            ->execute([$uid, $ref, $status, $tplId]);
    }

    // ---- precios ----

    #[DataProvider('prices')]
    public function testParsePriceUsd(string $in, ?string $out): void
    {
        self::assertSame($out, Admin::parsePriceUsd($in));
    }

    public static function prices(): array
    {
        return [['', '0.00'], ['0', '0.00'], ['4.99', '4.99'], ['4,5', '4.50'], ['999.99', '999.99'],
                ['1000', null], ['-1', null], ['1.999', null], ['abc', null], ['1e2', null]];
    }

    public function testPriceColumnIsDecimalAndRoundTrips(): void
    {
        self::$pdo->prepare('UPDATE templates SET price_usd = ? WHERE slug = ?')->execute(['2.50', 'premium-heart']);
        $tpl = self::$pdo->query("SELECT * FROM templates WHERE slug = 'premium-heart'")->fetch();
        self::assertSame(250, Access::priceInCents($tpl));
        self::$pdo->exec("UPDATE templates SET price_usd = 0 WHERE slug = 'premium-heart'");
    }

    // ---- acceso ----

    public function testAccessRules(): void
    {
        $free    = ['id' => 1, 'is_premium' => 0, 'price_usd' => '0.00'];
        $members = ['id' => 2, 'is_premium' => 1, 'price_usd' => '0.00'];
        $priced  = ['id' => 3, 'is_premium' => 0, 'price_usd' => '2.50'];
        $gratis  = ['is_premium' => 0];
        $socio   = ['is_premium' => 1];

        self::assertTrue(Access::canUse($gratis, $free, []));
        self::assertFalse(Access::canUse($gratis, $members, [3]), 'solo socios: comprar otra no la abre');
        self::assertFalse(Access::canUse($gratis, $priced, []));
        self::assertTrue(Access::canUse($gratis, $priced, [3]), 'compra individual aprobada');
        self::assertTrue(Access::canUse($socio, $members, []));
        self::assertTrue(Access::canUse($socio, $priced, []));
        self::assertFalse(Access::isPurchasable($members));
        self::assertTrue(Access::isPurchasable($priced));
    }

    public function testOnlyApprovedPaymentsUnlockTemplates(): void
    {
        $uid = $this->user('a@example.com');
        $this->pay($uid, 1, 'PENDING', 'r1');
        $this->pay($uid, 1, 'DECLINED', 'r2');
        self::assertSame([], Access::purchasedTemplateIds($uid));
        $this->pay($uid, 2, 'APPROVED', 'r3');
        $this->pay($uid, null, 'APPROVED', 'r4');   // membresía: no es de plantilla
        self::assertSame([2], Access::purchasedTemplateIds($uid));
        self::assertSame([], Access::purchasedTemplateIds($uid + 999), 'no se filtra entre usuarios');
    }

    // ---- niveles ----

    public function testTiersAreSeededAndOrdered(): void
    {
        $tiers = Access::tiers();
        self::assertSame(['romantico', 'pareja', 'eterno'], array_column($tiers, 'slug'));
        self::assertSame(['Romántico', 'Pareja', 'Eterno'], array_column($tiers, 'name'));
        self::assertSame([100, 399, 799], array_map([Access::class, 'tierPriceInCents'], $tiers));
        self::assertSame([2, 5, 12], array_map('intval', array_column($tiers, 'max_sites')));
        self::assertSame([3, 7, 14], array_map('intval', array_column($tiers, 'site_days')));
        self::assertSame([10, 45, 100], array_map('intval', array_column($tiers, 'bonus_coins')));
        self::assertSame([0, 10, 20], array_map('intval', array_column($tiers, 'topup_bonus_pct')));
        self::assertSame([0, 1, 1], array_map('intval', array_column($tiers, 'ad_free')));
        self::assertNull(Access::tier(999999));
    }

    public function testSiteAllowanceByTierAndExtraPurchases(): void
    {
        $free = $this->user('free@example.com');
        $tierId = (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = 'pareja'")->fetchColumn();
        $gold = $this->user('pareja@example.com');
        self::$pdo->prepare('UPDATE users SET membership_tier_id = ?, is_premium = 1 WHERE id = ?')->execute([$tierId, $gold]);

        $u = ['id' => $free, 'is_premium' => 0, 'membership_tier_id' => null];
        $m = ['id' => $gold, 'is_premium' => 1, 'membership_tier_id' => $tierId];
        self::assertSame(Access::FREE_SITE_LIMIT, Access::siteAllowance($u));
        self::assertSame(5, Access::siteAllowance($m));
        self::assertFalse(Access::isAdFree($u));
        self::assertTrue(Access::isAdFree($m));

        $this->pay($free, 1, 'PENDING', 'x1');                       // pendiente: no suma
        self::assertSame(3, Access::siteAllowance($u));
        $this->pay($free, 1, 'APPROVED', 'x2');                      // aprobada: +1 página
        self::assertSame(4, Access::siteAllowance($u));
        $this->pay($gold, 2, 'APPROVED', 'x3');
        self::assertSame(6, Access::siteAllowance($m));
    }

    public function testExtraTemplateDiscount(): void
    {
        $tpl = ['price_usd' => '2.50'];
        self::assertSame(250, Access::extraTemplatePriceInCents($tpl, null));
        self::assertSame(225, Access::extraTemplatePriceInCents($tpl, ['template_discount_pct' => 10]));
        self::assertSame(175, Access::extraTemplatePriceInCents($tpl, ['template_discount_pct' => 30]));
        self::assertSame(1, Access::extraTemplatePriceInCents(['price_usd' => '0.01'], ['template_discount_pct' => 90]), 'nunca por debajo del mínimo');
    }

    public function testMemberUnlocksPremiumTemplatesButNotBeyondLimit(): void
    {
        $premium = ['id' => 1, 'is_premium' => 1, 'price_usd' => '0.00'];
        self::assertTrue(Access::canUse(['is_premium' => 0, 'membership_tier_id' => 2], $premium, []));
        self::assertFalse(Access::canUse(['is_premium' => 0, 'membership_tier_id' => null], $premium, []));
    }

    // ---- vencimiento de membresías ----

    private function tierId(string $slug): int
    {
        return (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$slug'")->fetchColumn();
    }

    /** @return array<string,mixed> fila de users */
    private function member(string $email, ?string $slug, ?string $expires): array
    {
        $uid = $this->user($email);
        self::$pdo->prepare('UPDATE users SET membership_tier_id = ?, is_premium = ?, membership_expires_at = ? WHERE id = ?')
            ->execute([$slug === null ? null : $this->tierId($slug), $slug === null ? 0 : 1, $expires, $uid]);
        return (array) self::$pdo->query("SELECT * FROM users WHERE id = $uid")->fetch();
    }

    private static function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function testDurationsSeeded(): void
    {
        self::assertSame([1, 1, 2], array_map([Access::class, 'tierMonths'], Access::tiers()));
        self::assertSame('1 mes', Access::tierDurationLabel(['duration_months' => 1]));
        self::assertSame('2 meses', Access::tierDurationLabel(['duration_months' => 2]));
        self::assertSame(1, Access::tierMonths([]));
    }

    public function testExpiredPlanFallsBackToFree(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 60);
        $u = $this->member('exp@example.com', 'eterno', $past);
        self::assertNull(Access::userTier($u));
        self::assertFalse(Access::isMember($u));
        self::assertTrue(Access::wasMember($u));
        self::assertFalse(Access::isAdFree($u));
        self::assertSame(Access::FREE_SITE_LIMIT, Access::siteAllowance($u));
        self::assertSame(Access::FREE_SITE_DAYS, Access::siteDays($u));
        self::assertNull(Access::membershipExpiresAt($u));
        self::assertNull(Access::membershipDaysLeft($u));
        self::assertFalse(Access::canUse($u, ['id' => 1, 'is_premium' => 1, 'price_usd' => '0.00'], []));
        // is_premium legado sin plan: solo miembro si no ha vencido
        self::assertFalse(Access::isMember(['is_premium' => 1, 'membership_expires_at' => $past]));
        self::assertTrue(Access::isMember(['is_premium' => 1, 'membership_expires_at' => null]));
    }

    public function testNullExpiryNeverExpires(): void
    {
        $u = $this->member('null@example.com', 'pareja', null);
        self::assertSame('pareja', Access::userTier($u)['slug']);
        self::assertTrue(Access::isMember($u));
        self::assertFalse(Access::wasMember($u));
        self::assertNull(Access::membershipExpiresAt($u));
        self::assertNull(Access::membershipDaysLeft($u));
        self::assertTrue(Access::isAdFree($u));
    }

    public function testDaysLeftRoundsUp(): void
    {
        $now = 1_800_000_000;
        $u = ['is_premium' => 1, 'membership_tier_id' => 1, 'membership_expires_at' => gmdate('Y-m-d H:i:s', $now + 86400 * 2 + 10)];
        self::assertSame(3, Access::membershipDaysLeft($u, $now));
        self::assertSame($u['membership_expires_at'], Access::membershipExpiresAt($u, $now));
        self::assertSame(0, Access::membershipDaysLeft($u + [], $now + 86400 * 2 + 10), 'justo en el vencimiento: aún vigente, 0 días');
    }

    public function testRenewSameTierExtendsFromCurrentExpiry(): void
    {
        $now = self::utc('2026-01-31 12:00:00');
        $exp = '2026-03-15 08:00:00';
        $u = $this->member('renew@example.com', 'pareja', $exp);
        Access::applyTierPurchase(self::$pdo, (int) $u['id'], $this->tierId('pareja'), $now);
        self::assertSame('2026-04-15 08:00:00', self::$pdo->query("SELECT membership_expires_at FROM users WHERE id = {$u['id']}")->fetchColumn());
    }

    public function testHigherTierStartsFromNowAndEternoIsTwoMonths(): void
    {
        $now = self::utc('2026-05-10 12:00:00');
        $u = $this->member('up@example.com', 'romantico', '2026-06-30 00:00:00');
        Access::applyTierPurchase(self::$pdo, (int) $u['id'], $this->tierId('eterno'), $now);
        $row = self::$pdo->query("SELECT membership_tier_id, membership_expires_at FROM users WHERE id = {$u['id']}")->fetch();
        self::assertSame($this->tierId('eterno'), (int) $row['membership_tier_id']);
        self::assertSame('2026-07-10 12:00:00', $row['membership_expires_at']);

        $f = $this->member('free@example.com', null, null);   // sin plan: 1 mes desde ahora
        Access::applyTierPurchase(self::$pdo, (int) $f['id'], $this->tierId('romantico'), $now);
        self::assertSame('2026-06-10 12:00:00', self::$pdo->query("SELECT membership_expires_at FROM users WHERE id = {$f['id']}")->fetchColumn());
    }

    public function testLowerTierDoesNotDowngradeWhileHigherIsActive_ButExpiredCanBuyAnyTier(): void
    {
        $now = self::utc('2026-05-10 12:00:00');
        $u = $this->member('low@example.com', 'eterno', '2026-06-30 00:00:00');
        Access::applyTierPurchase(self::$pdo, (int) $u['id'], $this->tierId('romantico'), $now);
        $row = self::$pdo->query("SELECT membership_tier_id, membership_expires_at FROM users WHERE id = {$u['id']}")->fetch();
        self::assertSame($this->tierId('eterno'), (int) $row['membership_tier_id']);
        self::assertSame('2026-06-30 00:00:00', $row['membership_expires_at']);

        $old = $this->member('old@example.com', 'eterno', '2026-01-01 00:00:00');   // vencido
        Access::applyTierPurchase(self::$pdo, (int) $old['id'], $this->tierId('romantico'), $now);
        $row = self::$pdo->query("SELECT membership_tier_id, membership_expires_at FROM users WHERE id = {$old['id']}")->fetch();
        self::assertSame($this->tierId('romantico'), (int) $row['membership_tier_id']);
        self::assertSame('2026-06-10 12:00:00', $row['membership_expires_at']);
    }

    public function testLegacyNullExpirySameTierStartsFromNow(): void
    {
        $now = self::utc('2026-05-10 12:00:00');
        $u = $this->member('leg@example.com', 'pareja', null);
        Access::applyTierPurchase(self::$pdo, (int) $u['id'], $this->tierId('pareja'), $now);
        self::assertSame('2026-06-10 12:00:00', self::$pdo->query("SELECT membership_expires_at FROM users WHERE id = {$u['id']}")->fetchColumn());
    }

    public function testCanPurchaseTier(): void
    {
        $tiers = array_column(Access::tiers(), null, 'slug');
        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $pareja = $this->member('cp1@example.com', 'pareja', $future);
        self::assertFalse(Access::canPurchaseTier($pareja, $tiers['romantico']));
        self::assertTrue(Access::canPurchaseTier($pareja, $tiers['pareja']), 'renovar');
        self::assertTrue(Access::canPurchaseTier($pareja, $tiers['eterno']));
        $legacy = $this->member('cp2@example.com', 'pareja', null);
        self::assertTrue(Access::canPurchaseTier($legacy, $tiers['pareja']));
        $expired = $this->member('cp3@example.com', 'eterno', gmdate('Y-m-d H:i:s', time() - 5));
        self::assertTrue(Access::canPurchaseTier($expired, $tiers['romantico']));
        self::assertTrue(Access::canPurchaseTier(['id' => 1], $tiers['romantico']));
    }

    public function testViewAdFreeQueryRespectsExpiry(): void
    {
        $sql = 'SELECT (mt.ad_free = 1 AND (u.membership_expires_at IS NULL OR u.membership_expires_at > UTC_TIMESTAMP())) FROM users u
                LEFT JOIN membership_tiers mt ON mt.id = u.membership_tier_id WHERE u.id = ?';
        $vig = $this->member('v1@example.com', 'pareja', gmdate('Y-m-d H:i:s', time() + 3600));
        $ven = $this->member('v2@example.com', 'pareja', gmdate('Y-m-d H:i:s', time() - 3600));
        $st = self::$pdo->prepare($sql);
        $st->execute([$vig['id']]);
        self::assertSame(1, (int) $st->fetchColumn());
        $st->execute([$ven['id']]);
        self::assertSame(0, (int) $st->fetchColumn());
        self::assertStringContainsString('membership_expires_at', (string) file_get_contents(ROOT . '/public/view.php'));
    }

    // ---- perfil ----

    public function testChangeEmailValidatesAndEnforcesUniqueness(): void
    {
        $a = $this->user('a@example.com');
        $this->user('b@example.com');

        self::assertSame('Correo inválido.', Profile::changeEmail($a, 'no-es-correo'));
        self::assertSame('Ese correo ya está en uso.', Profile::changeEmail($a, 'B@Example.com'), 'colación case-insensitive');
        self::assertNull(Profile::changeEmail($a, '  Nuevo@Example.com '));
        self::assertSame('nuevo@example.com', self::$pdo->query("SELECT email FROM users WHERE id = $a")->fetchColumn());
        self::assertNull(Profile::changeEmail($a, 'nuevo@example.com'), 'mantener el propio correo no es duplicado');
    }

    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $uid = $this->user('p@example.com', 'clave-vieja-1');

        self::assertNotNull(Profile::changePassword($uid, 'incorrecta', 'clave-nueva-2', 'clave-nueva-2'));
        self::assertNotNull(Profile::changePassword($uid, 'clave-vieja-1', 'corta', 'corta'));
        self::assertNotNull(Profile::changePassword($uid, 'clave-vieja-1', 'clave-nueva-2', 'distinta-333'));
        self::assertNull(Profile::changePassword($uid, 'clave-vieja-1', 'clave-nueva-2', 'clave-nueva-2'));

        $hash = (string) self::$pdo->query("SELECT password_hash FROM users WHERE id = $uid")->fetchColumn();
        self::assertTrue(password_verify('clave-nueva-2', $hash));
        self::assertFalse(password_verify('clave-vieja-1', $hash));
    }

    public function testPaymentHistoryIsPerUserAndNamesTemplates(): void
    {
        $a = $this->user('h1@example.com');
        $b = $this->user('h2@example.com');
        $tplId = (int) self::$pdo->query("SELECT id FROM templates WHERE slug = 'premium-heart'")->fetchColumn();
        $this->pay($a, null, 'APPROVED', 'h-1');
        $this->pay($a, $tplId, 'PENDING', 'h-2');
        $this->pay($b, null, 'APPROVED', 'h-3');

        $rows = Profile::payments($a);
        self::assertCount(2, $rows);
        self::assertSame('Corazones (Premium)', $rows[0]['template_name']);
        self::assertNull($rows[1]['template_name']);
    }
}
