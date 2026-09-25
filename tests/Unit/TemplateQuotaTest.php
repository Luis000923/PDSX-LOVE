<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cupo mensual de plantillas "de membresía" (`templates.membership_unlocks = 1`): cada plan
 * desbloquea gratis un número limitado de plantillas DISTINTAS por mes; agotado, se paga en
 * monedas (nunca queda bloqueada del todo si tiene price_coins > 0).
 */
final class TemplateQuotaTest extends TestCase
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
        self::$pdo->exec('DELETE FROM template_unlocks');
        self::$pdo->exec('DELETE FROM coin_transactions');
        self::$pdo->exec('DELETE FROM user_sites');
        self::$pdo->exec("DELETE FROM templates WHERE slug LIKE 'tq_%'");
        self::$pdo->exec('DELETE FROM users');
    }

    /** @return array<string,mixed> */
    private function user(?int $tier = null, ?string $exp = '2099-01-01 00:00:00', int $coins = 0): array
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, membership_tier_id, is_premium, membership_expires_at, coins) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['tq' . uniqid() . '@t.test', 'x', $tier, $tier === null ? 0 : 1, $tier === null ? null : $exp, $coins]);
        $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([self::$pdo->lastInsertId()]);
        return $st->fetch();
    }

    /** Plantilla de prueba: is_premium=1, membership_unlocks=1, con un costo en monedas propio. */
    private function tpl(string $slug, int $priceCoins = 20): array
    {
        self::$pdo->prepare("INSERT INTO templates (slug, name, kind, category, file, price_coins, is_premium, membership_unlocks)
                              VALUES (?, ?, 'html', 'romantico', 'free-minimal.html', ?, 1, 1)")
            ->execute([$slug, $slug, $priceCoins]);
        $st = self::$pdo->prepare('SELECT * FROM templates WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch();
    }

    private function tierId(string $slug): int
    {
        return (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$slug'")->fetchColumn();
    }

    public function testFreePlanNeverGetsQuotaAndPaysCoins(): void
    {
        $u = $this->user(null, null, 100);
        $t = $this->tpl('tq_free1');
        self::assertSame(0, Access::templateUnlockUsage($u)['allowed']);
        self::assertSame(20, Access::coinCost($u, $t), 'sin membresía siempre paga monedas');
        self::assertTrue(Access::canUse($u, $t, []), 'nunca queda bloqueada si tiene precio en monedas');
    }

    public function testMembershipUnlocksWithinQuotaThenFallsBackToCoins(): void
    {
        $romantico = $this->tierId('romantico');   // cupo por defecto: 1/mes
        self::$pdo->prepare('UPDATE membership_tiers SET template_unlocks_per_month = 2 WHERE id = ?')->execute([$romantico]);
        $u = $this->user($romantico, coins: 100);
        $a = $this->tpl('tq_a');
        $b = $this->tpl('tq_b');
        $c = $this->tpl('tq_c');

        self::assertSame(0, Access::coinCost($u, $a), 'primera plantilla del mes: cubierta por el cupo');
        self::assertTrue(Access::tryCoverByMembershipQuota(self::$pdo, $u, $a));
        self::assertSame(0, Access::coinCost($u, $b), 'segunda plantilla del mes: aún hay cupo');
        self::assertTrue(Access::tryCoverByMembershipQuota(self::$pdo, $u, $b));

        $usage = Access::templateUnlockUsage($u);
        self::assertSame(['used' => 2, 'allowed' => 2, 'remaining' => 0], ['used' => $usage['used'], 'allowed' => $usage['allowed'], 'remaining' => $usage['remaining']]);

        self::assertFalse(Access::tryCoverByMembershipQuota(self::$pdo, $u, $c), 'cupo agotado: no se cubre');
        self::assertSame(20, Access::coinCost($u, $c), 'cae a monedas, no se bloquea');
        self::assertTrue(Access::canUse($u, $c, []));

        // Reusar una plantilla YA desbloqueada este mes no gasta cupo de nuevo.
        self::assertTrue(Access::tryCoverByMembershipQuota(self::$pdo, $u, $a));
        self::assertSame(2, Access::templateUnlockUsage($u)['used']);
    }

    public function testQuotaResetsNextMonth(): void
    {
        $romantico = $this->tierId('romantico');
        self::$pdo->prepare('UPDATE membership_tiers SET template_unlocks_per_month = 1 WHERE id = ?')->execute([$romantico]);
        $u = $this->user($romantico, coins: 100);
        $t = $this->tpl('tq_reset');
        $now = (int) strtotime('2026-09-15 12:00:00 UTC');
        self::assertTrue(Access::tryCoverByMembershipQuota(self::$pdo, $u, $t, $now));
        self::assertSame(0, Access::templateUnlockUsage($u, $now)['remaining']);

        $nextMonth = (int) strtotime('2026-10-02 12:00:00 UTC');
        self::assertSame(1, Access::templateUnlockUsage($u, $nextMonth)['remaining'], 'el cupo se reinicia el mes siguiente');
    }

    public function testZeroCoinPriceWithoutMembershipStaysLocked(): void
    {
        // Sin coste en monedas de respaldo, una plantilla con cupo mensual mal configurada
        // (price_coins = 0) no debe quedar accesible a todo el mundo sin membresía.
        $u = $this->user(null, null, 100);
        $t = $this->tpl('tq_nocoins', 0);
        self::assertFalse(Access::canUse($u, $t, []));
    }

    public function testPaidUsdPurchaseBypassesQuotaAndCoins(): void
    {
        $t = $this->tpl('tq_owned');
        $u = $this->user(null, null, 0);
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, template_id) VALUES (?, 'r', 100, 'APPROVED', ?)")
            ->execute([$u['id'], $t['id']]);
        self::assertSame(0, Access::coinCost($u, $t));
        self::assertTrue(Access::canUse($u, $t, [(int) $t['id']]));
    }

    public function testLegacyMembershipUnlocksZeroKeepsUnlimitedAccess(): void
    {
        // membership_unlocks = 0 (comportamiento clásico, plantillas ya existentes): la membresía
        // la desbloquea siempre, sin tocar el cupo mensual ni el fallback de monedas.
        self::$pdo->prepare("INSERT INTO templates (slug, name, kind, category, file, price_coins, is_premium, membership_unlocks)
                              VALUES ('tq_legacy', 'legacy', 'html', 'romantico', 'free-minimal.html', 0, 1, 0)")->execute();
        $st = self::$pdo->prepare('SELECT * FROM templates WHERE slug = ?');
        $st->execute(['tq_legacy']);
        $t = $st->fetch();

        $romantico = $this->tierId('romantico');
        $member = $this->user($romantico);
        self::assertTrue(Access::canUse($member, $t, []));
        self::assertSame(0, Access::templateUnlockUsage($member)['used'], 'no consume cupo');

        $free = $this->user(null, null, 100);
        self::assertFalse(Access::canUse($free, $t, []), 'sin membresía sigue bloqueada (price_coins=0)');
    }
}
