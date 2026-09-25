<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';

/** Referidos, check-in diario y cofres de aniversario. */
final class RetentionTest extends CreatorsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::$pdo->exec("DELETE FROM settings WHERE `key` IN ('referral_pct', 'checkin_coins')");
        Admin::setSetting('referral_pct', '');
    }

    private function coinsOf(int $uid): int
    {
        return (int) self::$pdo->query("SELECT coins FROM users WHERE id = $uid")->fetchColumn();
    }

    private function pay(int $uid, int $cents = 500, ?int $coins = 50, string $status = 'APPROVED'): int
    {
        self::$pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, status, coins) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, 'R-' . bin2hex(random_bytes(6)), $cents, $status, $coins]);
        return (int) self::$pdo->lastInsertId();
    }

    private function site(int $uid, string $created, ?string $expires): int
    {
        $tpl = (int) self::$pdo->query('SELECT id FROM templates ORDER BY id LIMIT 1')->fetchColumn();
        self::$pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $tpl, bin2hex(random_bytes(8)), '{}', $created, $expires]);
        return (int) self::$pdo->lastInsertId();
    }

    // ---------------------------------------------------------- referidos ---

    public function testCodeIsStableAndValid(): void
    {
        $u = $this->user('Ref');
        $c = Referrals::codeFor((int) $u['id']);
        self::assertSame($c, Referrals::normalize(strtolower($c)));
        self::assertSame($c, Referrals::codeFor((int) $u['id']));
        self::assertSame((int) $u['id'], Referrals::ownerOf($c));
        self::assertSame('', Referrals::normalize('abc'));
        self::assertNull(Referrals::ownerOf('ZZZZZZZZ'));
    }

    public function testAttachIgnoresUnknownAndSelfCodes(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        self::assertFalse(Referrals::attach(self::$pdo, (int) $b['id'], 'ZZZZZZZZ'));
        self::assertFalse(Referrals::attach(self::$pdo, (int) $a['id'], Referrals::codeFor((int) $a['id'])));
        self::assertTrue(Referrals::attach(self::$pdo, (int) $b['id'], Referrals::codeFor((int) $a['id'])));
        self::assertFalse(Referrals::attach(self::$pdo, (int) $b['id'], Referrals::codeFor((int) $a['id'])), 'ya tiene invitador');
    }

    public function testFirstPurchaseRewardsReferrerOnlyOnce(): void
    {
        $a = $this->user('Inv', 0);
        $b = $this->user('Nuevo', 0);
        Referrals::attach(self::$pdo, (int) $b['id'], Referrals::codeFor((int) $a['id']));

        Payments::fulfill(self::$pdo, $this->pay((int) $b['id'], 500, 50), 'test');
        self::assertSame(5, $this->coinsOf((int) $a['id']), '10 % de 50 monedas');
        Payments::fulfill(self::$pdo, $this->pay((int) $b['id'], 500, 50), 'test');
        self::assertSame(5, $this->coinsOf((int) $a['id']), 'la 2.ª compra no premia');
        self::assertSame(['invited' => 1, 'rewarded' => 1, 'coins' => 5], Referrals::stats((int) $a['id']));
    }

    public function testRepeatedFulfillDoesNotDoubleReward(): void
    {
        $a = $this->user('Inv2');
        $b = $this->user('Nuevo2');
        Referrals::attach(self::$pdo, (int) $b['id'], Referrals::codeFor((int) $a['id']));
        $p = $this->pay((int) $b['id']);
        Payments::fulfill(self::$pdo, $p, 'test');
        Payments::fulfill(self::$pdo, $p, 'test');
        self::assertSame(5, $this->coinsOf((int) $a['id']));
    }

    public function testNoRewardWithoutReferrerOrForFreeOrUnapprovedPayments(): void
    {
        $a = $this->user('Inv3');
        $b = $this->user('Solo');
        Payments::fulfill(self::$pdo, $this->pay((int) $b['id']), 'test');
        $c = $this->user('Cup');
        Referrals::attach(self::$pdo, (int) $c['id'], Referrals::codeFor((int) $a['id']));
        Payments::fulfill(self::$pdo, $this->pay((int) $c['id'], 0, null), 'test');
        Payments::fulfill(self::$pdo, $this->pay((int) $c['id'], 500, 50, 'PENDING'), 'test');
        self::assertSame(0, $this->coinsOf((int) $a['id']));
    }

    public function testCommissionUsesConfiguredPercentAndMinimumOne(): void
    {
        Admin::setSetting('referral_pct', '20');
        self::assertSame(10, Referrals::commission(50));
        self::assertSame(1, Referrals::commission(1));
        self::assertSame(0, Referrals::commission(0));
        Admin::setSetting('referral_pct', '999');
        self::assertSame(Referrals::MAX_PCT, Referrals::pct());
    }

    // ------------------------------------------------------------ check-in ---

    public function testCheckinOncePerCalendarDay(): void
    {
        $u = (int) $this->user('Chk')['id'];
        $d1 = new DateTimeImmutable('2026-03-10 15:00:00', new DateTimeZone('America/El_Salvador'));
        self::assertTrue(Checkin::canClaim($u, $d1));
        $r = Checkin::claim($u, $d1);
        self::assertTrue($r['ok']);
        self::assertSame(1, $this->coinsOf($u));
        $again = Checkin::claim($u, $d1->modify('+5 hours'));
        self::assertFalse($again['ok']);
        self::assertFalse(Checkin::canClaim($u, $d1->modify('+5 hours')));
        self::assertSame(1, $this->coinsOf($u), 'sin doble cobro');
        self::assertTrue(Checkin::claim($u, $d1->modify('+1 day'))['ok'], 'al día siguiente sí');
        self::assertSame(2, $this->coinsOf($u));
    }

    public function testCheckinDayFollowsElSalvadorTimezone(): void
    {
        $u = (int) $this->user('Tz')['id'];
        // 23:30 en El Salvador = 05:30 UTC del día siguiente: sigue siendo el mismo día local.
        $late = new DateTimeImmutable('2026-03-10 23:30:00', new DateTimeZone('America/El_Salvador'));
        self::assertTrue(Checkin::claim($u, $late)['ok']);
        self::assertFalse(Checkin::claim($u, $late->setTimezone(new DateTimeZone('UTC')))['ok']);
        self::assertSame(1, $this->coinsOf($u));
    }

    public function testCheckinSuspendedAndUnknownAccountsCannotClaim(): void
    {
        $u = (int) $this->user('Sus')['id'];
        self::$pdo->exec("UPDATE users SET is_suspended = 1 WHERE id = $u");
        self::assertFalse(Checkin::claim($u)['ok']);
        self::assertFalse(Checkin::claim(999999)['ok']);
        self::assertSame(0, $this->coinsOf($u));
    }

    public function testCheckinAmountIsConfigurableAndClamped(): void
    {
        Admin::setSetting('checkin_coins', '2');
        self::assertSame(2, Checkin::coins());
        Admin::setSetting('checkin_coins', '0');
        self::assertSame(Checkin::DEFAULT_COINS, Checkin::coins());
        Admin::setSetting('checkin_coins', '500');
        self::assertSame(Checkin::MAX_COINS, Checkin::coins());
        Admin::setSetting('checkin_coins', '');
    }

    // --------------------------------------------------------------- cofres ---

    public function testChestAppearsAtMilestoneAndOpensOnce(): void
    {
        $u = (int) $this->user('Cof')['id'];
        $now = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
        $s = $this->site($u, '2026-05-01 12:00:00', '2027-01-01 00:00:00');   // 31 días
        self::assertCount(1, Chests::available($u, $now));
        self::assertCount(1, Chests::available($u, $now), 'sync idempotente');
        $id = (int) Chests::available($u, $now)[0]['id'];
        $r = Chests::open($u, $id);
        self::assertTrue($r['ok']);
        self::assertSame(20, $this->coinsOf($u));
        self::assertFalse(Chests::open($u, $id)['ok'], 'no se abre dos veces');
        self::assertSame(20, $this->coinsOf($u));
        self::assertSame([], Chests::available($u, $now));
        self::assertGreaterThan(0, $s);
    }

    public function testLongLivedSiteGetsAllThreeMilestones(): void
    {
        $u = (int) $this->user('Larga')['id'];
        $now = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));
        $this->site($u, '2025-01-01 00:00:00', null);
        $keys = array_column(Chests::available($u, $now), 'milestone');
        self::assertSame(['1m', '6m', '12m'], $keys);
    }

    public function testNoChestForYoungOrExpiredSitesOrOtherUsers(): void
    {
        $u = (int) $this->user('Joven')['id'];
        $o = (int) $this->user('Otro')['id'];
        $now = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));
        $this->site($u, '2026-05-20 00:00:00', '2027-01-01 00:00:00');   // 12 días
        $this->site($u, '2025-01-01 00:00:00', '2026-01-01 00:00:00');   // vencida
        self::assertSame([], Chests::available($u, $now));

        $this->site($u, '2026-01-01 00:00:00', null);
        $id = (int) Chests::available($u, $now)[0]['id'];
        self::assertFalse(Chests::open($o, $id)['ok'], 'un cofre ajeno no se abre');
        self::assertSame(0, $this->coinsOf($o));
    }

    public function testDeletedSiteDropsItsChest(): void
    {
        $u = (int) $this->user('Borra')['id'];
        $now = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));
        $s = $this->site($u, '2026-01-01 00:00:00', null);
        Chests::sync($u, $now);
        self::$pdo->exec("DELETE FROM user_sites WHERE id = $s");
        self::assertSame(0, (int) self::$pdo->query("SELECT COUNT(*) FROM anniversary_chests WHERE user_id = $u")->fetchColumn());
    }
}
