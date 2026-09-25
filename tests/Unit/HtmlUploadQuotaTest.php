<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cupo mensual de subidas de HTML propio: Gratis 1 · Romántico 3 · Pareja 6 · Eterno 12. */
final class HtmlUploadQuotaTest extends TestCase
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
        self::$pdo->exec('DELETE FROM html_uploads');
        self::$pdo->exec('DELETE FROM users');
    }

    /** @return array<string,mixed> */
    private function user(?string $tierSlug = null, string $exp = '2099-01-01 00:00:00'): array
    {
        $tier = $tierSlug === null ? null : (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$tierSlug'")->fetchColumn();
        self::$pdo->prepare('INSERT INTO users (email, password_hash, membership_tier_id, is_premium, membership_expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['hq' . uniqid() . '@t.test', 'x', $tier, $tier === null ? 0 : 1, $tier === null ? null : $exp]);
        $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([self::$pdo->lastInsertId()]);
        return $st->fetch();
    }

    private function record(array $u, ?int $now = null): ?int
    {
        return Access::tryRecordHtmlUpload(self::$pdo, $u, hash('sha256', uniqid('', true)), 1234, $now);
    }

    public function testAllowancePerPlan(): void
    {
        self::assertSame(1, Access::htmlUploadUsage($this->user())['allowed']);
        self::assertSame(3, Access::htmlUploadUsage($this->user('romantico'))['allowed']);
        self::assertSame(6, Access::htmlUploadUsage($this->user('pareja'))['allowed']);
        self::assertSame(12, Access::htmlUploadUsage($this->user('eterno'))['allowed']);
        self::assertSame(Access::FREE_MONTHLY_HTML_UPLOADS, 1);
    }

    /** @return array<string,array{0:?string,1:int}> */
    public static function plans(): array
    {
        return ['gratis' => [null, 1], 'romántico' => ['romantico', 3], 'pareja' => ['pareja', 6], 'eterno' => ['eterno', 12]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function testRecordsUpToTheAllowanceThenRefuses(?string $plan, int $max): void
    {
        $u = $this->user($plan);
        for ($i = 0; $i < $max; $i++) {
            self::assertNotNull($this->record($u), "la subida " . ($i + 1) . " de $max debe aceptarse");
        }
        self::assertNull($this->record($u), 'la siguiente supera el cupo');
        $usage = Access::htmlUploadUsage($u);
        self::assertSame([$max, $max, 0], [$usage['used'], $usage['allowed'], $usage['remaining']]);
        self::assertSame($max, (int) self::$pdo->query('SELECT COUNT(*) FROM html_uploads WHERE user_id = ' . (int) $u['id'])->fetchColumn(), 'una subida rechazada no deja rastro');
    }

    public function testQuotaResetsNextMonth(): void
    {
        $u = $this->user();
        $now = (int) strtotime('2026-09-15 12:00:00 UTC');
        self::assertNotNull($this->record($u, $now));
        self::$pdo->exec("UPDATE html_uploads SET created_at = '2026-09-15 12:00:00'");
        self::assertNull($this->record($u, $now));
        self::assertNotNull($this->record($u, (int) strtotime('2026-10-02 12:00:00 UTC')), 'octubre: cupo nuevo');
    }

    public function testDeletingTheSiteDoesNotRefundTheQuota(): void
    {
        $u = $this->user();
        $id = $this->record($u);
        self::assertNotNull($id);
        Access::linkHtmlUpload(self::$pdo, $id, 999999);   // la página ya no existe: el libro sigue contando
        self::assertNull($this->record($u));
    }

    public function testExpiredPlanFallsBackToFreeAllowance(): void
    {
        $u = $this->user('eterno', '2000-01-01 00:00:00');   // plan vencido → cupo del plan gratuito
        self::assertSame(1, Access::htmlUploadUsage($u)['allowed']);
    }

    public function testBoundaryOfElSalvadorMonth(): void
    {
        $u = $this->user();
        self::assertNotNull($this->record($u, (int) strtotime('2026-09-30 05:59:59 UTC')));
        self::$pdo->exec("UPDATE html_uploads SET created_at = '2026-09-30 05:59:59'");
        // 05:59:59 UTC del 30/sep sigue siendo septiembre local; 06:00:00 UTC del 1/oct ya es octubre
        self::assertSame(1, Access::htmlUploadUsage($u, (int) strtotime('2026-09-30 05:59:59 UTC'))['used']);
        self::assertSame(0, Access::htmlUploadUsage($u, (int) strtotime('2026-10-01 06:00:00 UTC'))['used']);
    }
}
