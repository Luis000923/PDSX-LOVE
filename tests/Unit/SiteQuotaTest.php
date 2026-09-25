<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cuota mensual del plan gratuito (mes calendario en hora de El Salvador, UTC-6). */
final class SiteQuotaTest extends TestCase
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

    /** @return array<string,mixed> */
    private function user(?int $tier = null, ?string $exp = null): array
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, membership_tier_id, is_premium, membership_expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['q' . uniqid() . '@t.test', 'x', $tier, $tier === null ? 0 : 1, $exp]);
        $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([self::$pdo->lastInsertId()]);
        return $st->fetch();
    }

    private function log(array $u, string $at, ?int $tier = null, string $kind = 'create'): void
    {
        self::$pdo->prepare('INSERT INTO site_creations (user_id, tier_id, kind, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$u['id'], $tier, $kind, $at]);
    }

    private function tplId(): int
    {
        return (int) self::$pdo->query('SELECT id FROM templates WHERE price_coins = 0 AND is_premium = 0 AND price_usd = 0 LIMIT 1')->fetchColumn();
    }

    private function ts(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    public function testMonthBoundsAndLabel(): void
    {
        // 2026-10-01 05:59:59 UTC = 30 sep 23:59:59 en El Salvador: aún septiembre
        [$from, $to] = Access::monthBounds($this->ts('2026-10-01 05:59:59'));
        self::assertSame(['2026-09-01 06:00:00', '2026-10-01 06:00:00'], [$from, $to]);
        [$from, $to] = Access::monthBounds($this->ts('2026-10-01 06:00:00'));
        self::assertSame(['2026-10-01 06:00:00', '2026-11-01 06:00:00'], [$from, $to]);
        self::assertSame('1 de octubre', Access::monthResetLabel('2026-10-01 06:00:00'));
    }

    public function testMonthlyCreationsCountAndBorder(): void
    {
        $u = $this->user();
        $this->log($u, '2026-09-01 06:00:00');   // primer instante de septiembre local
        $this->log($u, '2026-10-01 05:59:59');   // último de septiembre local
        $this->log($u, '2026-09-01 05:59:59');   // agosto local: no cuenta
        $this->log($u, '2026-10-01 06:00:00');   // octubre: no cuenta
        $now = $this->ts('2026-09-15 12:00:00');
        $usage = Access::siteUsage($u, $now);
        self::assertSame(['month', 2, 3, 1, '2026-10-01 06:00:00'], [$usage['mode'], $usage['used'], $usage['allowed'], $usage['remaining'], $usage['resets_at']]);
        self::assertFalse(Access::atSiteLimit($u, $now));
        // mes nuevo reinicia
        self::assertSame(1, Access::siteUsage($u, $this->ts('2026-10-01 06:00:00'))['used']);
        self::assertSame(0, Access::siteUsage($u, $this->ts('2026-11-15 12:00:00'))['used']);
    }

    public function testExtrasAddAndPaidTiersIgnoreMonthlyQuota(): void
    {
        $u = $this->user();
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, template_id) VALUES (?, 'r1', 250, 'APPROVED', ?)")
            ->execute([$u['id'], $this->tplId()]);
        self::assertSame(4, Access::siteUsage($u)['allowed']);

        $tier = (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = 'romantico'")->fetchColumn();
        $p = $this->user($tier, '2099-01-01 00:00:00');
        for ($i = 0; $i < 5; $i++) {
            $this->log($p, gmdate('Y-m-d H:i:s'), $tier);
        }
        $usage = Access::siteUsage($p);
        self::assertSame(['active', 0, 2, null], [$usage['mode'], $usage['used'], $usage['allowed'], $usage['resets_at']]);
        self::assertFalse(Access::isFreePlan($p));
    }

    public function testExpiredPaidPlanFallsBackAndOnlyNullTierCounts(): void
    {
        $tier = (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = 'romantico'")->fetchColumn();
        $u = $this->user($tier, '2020-01-01 00:00:00');   // vencido
        $now = $this->ts('2026-09-15 12:00:00');
        $this->log($u, '2026-09-10 12:00:00', $tier);
        $this->log($u, '2026-09-11 12:00:00', null);
        self::assertTrue(Access::isFreePlan($u, $now));
        self::assertSame(1, Access::siteUsage($u, $now)['used']);
    }

    public function testCreateRenewConsumeQuotaAndDeleteDoesNotRefund(): void
    {
        $u = $this->user();
        $tpl = ['id' => $this->tplId(), 'price_coins' => 0];
        $slugs = [];
        for ($i = 0; $i < 2; $i++) {
            $r = Sites::create($u, $tpl, ['your_name' => 'A']);
            self::assertArrayHasKey('slug', $r);
            $slugs[] = $r['slug'];
        }
        self::$pdo->prepare('DELETE FROM user_sites WHERE slug = ?')->execute([$slugs[0]]);   // borrar no devuelve cupo
        self::assertSame(2, Access::siteUsage($u)['used']);

        $site = (int) self::$pdo->query("SELECT id FROM user_sites WHERE slug = '{$slugs[1]}'")->fetchColumn();
        self::assertNull(Sites::renew($u, $site));           // renovar consume el tercero
        self::assertSame(3, Access::siteUsage($u)['used']);
        self::assertSame('renew', self::$pdo->query('SELECT kind FROM site_creations ORDER BY id DESC LIMIT 1')->fetchColumn());

        $r = Sites::create($u, $tpl, ['your_name' => 'A']);
        self::assertStringContainsString('Usaste tus páginas gratuitas de este mes', (string) ($r['error'] ?? ''));
        self::assertStringContainsString('Usaste', (string) Sites::renew($u, $site));
    }

    public function testPaidCreateLogsTier(): void
    {
        $tier = (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = 'romantico'")->fetchColumn();
        $u = $this->user($tier, '2099-01-01 00:00:00');
        $r = Sites::create($u, ['id' => $this->tplId(), 'price_coins' => 0], ['your_name' => 'A']);
        self::assertArrayHasKey('slug', $r);
        self::assertSame($tier, (int) self::$pdo->query('SELECT tier_id FROM site_creations')->fetchColumn());
        self::assertSame(0, Access::freeCreationsThisMonth((int) $u['id']));
    }
}
