<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Premios a creadores (hitos, cierre mensual, mejora temporal, no retroactividad) y colaboradores destacados. */
#[RunTestsInSeparateProcesses]
final class CreatorAwardsTest extends CreatorsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Awards::putSetting(Awards::LAUNCH_KEY, '2026-08');
        Awards::putSetting(Creators::LAUNCH_KEY, '2026-08');
    }

    /** Aprueba N plantillas nuevas por la ruta real (Creators::approve). */
    private function approveOne(int $owner, int $admin): void
    {
        $t = $this->utpl($owner, 'pending', 20);
        rename(Creators::htmlPath($t['slug']), Creators::pendingPath($t['slug']));
        self::assertSame([], Creators::approve($admin, (int) $t['id']));
    }

    private function sites(int $buyer, int $tplId, int $n, string $at): void
    {
        for ($i = 0; $i < $n; $i++) {
            self::$pdo->prepare("INSERT INTO user_sites (user_id, template_id, slug, data, expires_at, created_at) VALUES (?, ?, ?, '{}', NULL, ?)")
                ->execute([$buyer, $tplId, substr(bin2hex(random_bytes(6)), 0, 10), $at]);
        }
    }

    public function testMilestonesAreGrantedOnceWithCoinsAndBadges(): void
    {
        $c = $this->user('Hitos');
        $a = $this->user('Adm');
        $this->approveOne((int) $c['id'], (int) $a['id']);
        self::assertSame(20, $this->coins((int) $c['id']));
        self::assertContains('creador', Awards::badgeKeysFor([(int) $c['id']])[(int) $c['id']]);
        $this->approveOne((int) $c['id'], (int) $a['id']);
        self::assertSame(20, $this->coins((int) $c['id']), 'el hito 3 aún no');
        $this->approveOne((int) $c['id'], (int) $a['id']);
        self::assertSame(80, $this->coins((int) $c['id']));
        $this->approveOne((int) $c['id'], (int) $a['id']);
        $this->approveOne((int) $c['id'], (int) $a['id']);
        self::assertSame(200, $this->coins((int) $c['id']), '20 + 60 + 120');
        self::assertContains('creador_estrella', Awards::badgeKeysFor([(int) $c['id']])[(int) $c['id']]);
        // Idempotencia: reevaluar no duplica nada.
        self::$pdo->beginTransaction();
        self::assertSame(0, CreatorAwards::evaluateMilestones(self::$pdo, (int) $c['id']));
        self::$pdo->commit();
        self::assertSame(200, $this->coins((int) $c['id']));
        self::assertSame(200, $this->ledger((int) $c['id']));
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM award_grants WHERE kind = 'creator_ms'"));
    }

    public function testMonthlyCloseGrantsCoinsBadgeAndTemporaryTierToNumberOne(): void
    {
        $a = $this->user('Uno');
        $b = $this->user('Dos');
        $low = $this->user('Bajo');
        $buyer = $this->user('Comprador');
        $tpls = [];
        foreach ([$a, $b, $low] as $c) {
            for ($i = 0; $i < 3; $i++) {
                $t = $this->utpl((int) $c['id'], 'approved');
                self::$pdo->exec("UPDATE templates SET reviewed_at = '2026-07-01 00:00:00' WHERE id = " . (int) $t['id']);
                $tpls[(int) $c['id']][] = (int) $t['id'];
            }
        }
        $this->sites((int) $buyer['id'], $tpls[(int) $a['id']][0], 25, '2026-08-10 12:00:00');
        $this->sites((int) $buyer['id'], $tpls[(int) $b['id']][0], 20, '2026-08-11 12:00:00');
        $this->sites((int) $buyer['id'], $tpls[(int) $low['id']][0], 19, '2026-08-12 12:00:00');
        $this->sites((int) $a['id'], $tpls[(int) $a['id']][1], 50, '2026-08-12 12:00:00');   // autouso: no cuenta
        $now = (int) (new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')))->format('U');
        $r = Awards::closePendingMonths(null, $now);
        self::assertSame(['2026-08'], $r['closed']);
        self::assertSame(80, $this->coins((int) $a['id']));
        self::assertSame(80, $this->coins((int) $b['id']));
        self::assertSame(0, $this->coins((int) $low['id']), '19 usos < 20');
        self::assertContains('colaborador_mes', Awards::badgeKeysFor([(int) $a['id']])[(int) $a['id']]);
        $ua = $this->row('users', (int) $a['id']);
        $ub = $this->row('users', (int) $b['id']);
        self::assertSame($this->tierId('pareja'), (int) $ua['bonus_tier_id'], 'el n.º 1 recibe la mejora temporal');
        self::assertNull($ub['bonus_tier_id']);
        $days = (strtotime((string) $ua['bonus_tier_expires_at'] . ' UTC') - time()) / 86400;
        self::assertEqualsWithDelta(7, $days, 1);
        // Idempotencia: cerrar otra vez no duplica monedas ni días.
        self::assertSame([], Awards::closePendingMonths(null, $now)['closed']);
        Awards::closeMonth(self::$pdo, '2026-08');
        self::assertSame(80, $this->coins((int) $a['id']));
        self::assertSame($ua['bonus_tier_expires_at'], $this->row('users', (int) $a['id'])['bonus_tier_expires_at']);
        self::assertSame($this->coins((int) $a['id']), $this->ledger((int) $a['id']));
    }

    public function testNotRetroactiveBeforeCreatorsLaunchMonth(): void
    {
        Awards::putSetting(Creators::LAUNCH_KEY, '2026-09');
        $c = $this->user('Antes');
        $buyer = $this->user('Comp');
        $tplIds = [];
        for ($i = 0; $i < 3; $i++) {
            $t = $this->utpl((int) $c['id'], 'approved');
            self::$pdo->exec("UPDATE templates SET reviewed_at = '2026-07-01 00:00:00' WHERE id = " . (int) $t['id']);
            $tplIds[] = (int) $t['id'];
        }
        $this->sites((int) $buyer['id'], $tplIds[0], 30, '2026-08-10 12:00:00');
        Awards::closePendingMonths(null, (int) (new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')))->format('U'));
        self::assertSame(0, $this->coins((int) $c['id']));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM award_grants WHERE kind LIKE 'creator%'"));
    }

    public function testTierRuleAndDisablingTemporaryTier(): void
    {
        $cfg = Creators::DEFAULTS;
        $cfg['top_tier_days'] = 0;
        Creators::saveConfig($cfg);
        $c = $this->user('SinBonus');
        $buyer = $this->user('B');
        for ($i = 0; $i < 3; $i++) {
            $t = $this->utpl((int) $c['id'], 'approved');
            self::$pdo->exec("UPDATE templates SET reviewed_at = '2026-07-01 00:00:00' WHERE id = " . (int) $t['id']);
            $last = (int) $t['id'];
        }
        $this->sites((int) $buyer['id'], $last, 20, '2026-08-10 12:00:00');
        Awards::closePendingMonths(null, (int) (new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')))->format('U'));
        self::assertSame(80, $this->coins((int) $c['id']));
        self::assertNull($this->row('users', (int) $c['id'])['bonus_tier_id'], 'días = 0 desactiva la mejora');
    }

    public function testBonusTierNeverReplacesHigherOrPaidPlan(): void
    {
        $u = $this->user('Eterno', 0, 'eterno');
        $now = new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('UTC'));
        self::$pdo->beginTransaction();
        self::assertTrue(CreatorAwards::applyBonusTier(self::$pdo, (int) $u['id'], 'pareja', 7, $now));
        self::$pdo->commit();
        $row = $this->row('users', (int) $u['id']);
        self::assertSame($this->tierId('eterno'), (int) $row['membership_tier_id'], 'el plan comprado no se toca');
        self::assertSame('2099-01-01 00:00:00', $row['membership_expires_at']);
        self::assertSame('2026-09-08 00:00:00', $row['bonus_tier_expires_at']);
        // Mismo plan: suma días. Uno inferior no degrada un bonus superior vigente.
        self::$pdo->beginTransaction();
        CreatorAwards::applyBonusTier(self::$pdo, (int) $u['id'], 'pareja', 7, $now);
        CreatorAwards::applyBonusTier(self::$pdo, (int) $u['id'], 'romantico', 30, $now);
        self::$pdo->commit();
        $row = $this->row('users', (int) $u['id']);
        self::assertSame('2026-09-15 00:00:00', $row['bonus_tier_expires_at']);
        self::assertSame($this->tierId('pareja'), (int) $row['bonus_tier_id']);
        self::assertFalse(CreatorAwards::applyBonusTier(self::$pdo, (int) $u['id'], 'noexiste', 7, $now));
    }

    public function testCollaboratorsRankingUsesAliasSuccessAndExcludesSelfUse(): void
    {
        $x = $this->user('Estrella');
        $y = $this->user('Promedio');
        $z = $this->user('Nueva');
        $buyer = $this->user('Compra');
        $tx1 = $this->utpl((int) $x['id']);
        $tx2 = $this->utpl((int) $x['id']);
        $ty = $this->utpl((int) $y['id']);
        $tz = $this->utpl((int) $z['id']);
        $this->sites((int) $buyer['id'], (int) $tx1['id'], 5, '2026-09-01 00:00:00');
        $this->sites((int) $buyer['id'], (int) $tx2['id'], 6, '2026-09-01 00:00:00');
        $this->sites((int) $buyer['id'], (int) $ty['id'], 9, '2026-09-01 00:00:00');
        $this->sites((int) $z['id'], (int) $tz['id'], 40, '2026-09-01 00:00:00');   // autouso: no cuenta
        $this->sites((int) $buyer['id'], (int) $tz['id'], 4, '2026-09-01 00:00:00');   // 4 < 5
        $rows = Creators::collaborators();
        self::assertSame(['Estrella', 'Promedio'], array_column($rows, 'alias'));
        self::assertSame(2, $rows[0]['successful']);
        self::assertSame(11, $rows[0]['uses']);
        self::assertSame(1, $rows[1]['successful']);
        foreach ($rows as $r) {
            self::assertArrayNotHasKey('email', $r);
        }
        self::$pdo->exec('UPDATE users SET is_suspended = 1 WHERE id = ' . (int) $x['id']);
        self::assertSame(['Promedio'], array_column(Creators::collaborators(), 'alias'), 'suspendidos fuera');
        $cfg = Creators::DEFAULTS;
        $cfg['success_uses'] = 10;
        Creators::saveConfig($cfg);
        self::assertSame([], Creators::collaborators(), 'el umbral N es configurable');
    }
}
