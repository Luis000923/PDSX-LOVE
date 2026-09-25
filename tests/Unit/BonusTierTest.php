<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Mejora temporal de plan (users.bonus_tier_*): todas las combinaciones principal/bonus/vencidos en Access. */
#[RunTestsInSeparateProcesses]
final class BonusTierTest extends CreatorsTestCase
{
    /** @return array<string,mixed> */
    private function withBonus(?string $main, string $mainExp, ?string $bonus, ?string $bonusExp): array
    {
        $u = $this->user('U' . uniqid(), 0, $main, $mainExp);
        self::$pdo->prepare('UPDATE users SET bonus_tier_id = ?, bonus_tier_expires_at = ? WHERE id = ?')
            ->execute([$bonus === null ? null : $this->tierId($bonus), $bonusExp, $u['id']]);
        return $this->row('users', (int) $u['id']);
    }

    private function slug(?array $t): ?string
    {
        return $t === null ? null : (string) $t['slug'];
    }

    public function testEffectiveTierIsTheHigherOfMainAndBonus(): void
    {
        $future = '2099-01-01 00:00:00';
        $past = '2020-01-01 00:00:00';
        $cases = [
            // [principal, venc. principal, bonus, venc. bonus] => [efectivo, principal]
            'sin nada'                 => [null, $future, null, null, null, null],
            'solo bonus'               => [null, $future, 'pareja', $future, 'pareja', null],
            'bonus superior'           => ['romantico', $future, 'pareja', $future, 'pareja', 'romantico'],
            'bonus inferior'           => ['eterno', $future, 'pareja', $future, 'eterno', 'eterno'],
            'mismo plan'               => ['pareja', $future, 'pareja', $future, 'pareja', 'pareja'],
            'bonus vencido'            => ['romantico', $future, 'pareja', $past, 'romantico', 'romantico'],
            'principal vencido'        => ['eterno', $past, 'pareja', $future, 'pareja', null],
            'ambos vencidos'           => ['eterno', $past, 'pareja', $past, null, null],
            'bonus sin fecha'          => [null, $future, 'pareja', null, null, null],
        ];
        foreach ($cases as $name => [$m, $me, $b, $be, $eff, $main]) {
            $u = $this->withBonus($m, $me, $b, $be);
            self::assertSame($eff, $this->slug(Access::userTier($u)), "efectivo: $name");
            self::assertSame($main, $this->slug(Access::mainTier($u)), "principal: $name");
        }
    }

    public function testBonusCountsAsMemberButNotAsCurrentPlanForRenewalScreens(): void
    {
        $future = '2099-01-01 00:00:00';
        $u = $this->withBonus(null, $future, 'pareja', $future);
        self::assertTrue(Access::isMember($u));
        self::assertNull(Access::membershipExpiresAt($u), 'la mejora no es el «plan actual» con vencimiento');
        self::assertNull(Access::membershipDaysLeft($u));
        self::assertFalse(Access::wasMember($u));
        self::assertFalse(Access::isFreePlan($u), 'disfruta de los beneficios del plan');
        // No es comprable/renovable: puede comprar cualquier plan, incluso uno inferior al bonus.
        self::assertTrue(Access::canPurchaseTier($u, (array) Access::tier($this->tierId('romantico'))));
        $lapsed = $this->withBonus('eterno', '2020-01-01 00:00:00', 'pareja', $future);
        self::assertTrue(Access::wasMember($lapsed), '«Tu plan venció» sigue reflejando el principal');
        self::assertTrue(Access::isMember($lapsed));
        $noBonus = $this->withBonus(null, $future, null, null);
        self::assertFalse(Access::isMember($noBonus));
    }

    public function testBonusGrantsBenefitsButDoesNotAlterStoredQuotaLedgers(): void
    {
        $future = '2099-01-01 00:00:00';
        $u = $this->withBonus(null, $future, 'pareja', $future);
        self::assertSame(6, Access::htmlUploadUsage($u)['allowed'], 'cupo de HTML propio del plan Pareja');
        self::assertSame(3, Access::templateUnlockUsage($u)['allowed']);
        self::assertSame(7, Access::siteDays($u));
        // Un registro guardado con tier_id NULL (creado como gratuito) sigue contando como gratuito.
        self::$pdo->prepare('INSERT INTO site_creations (user_id, tier_id, kind) VALUES (?, NULL, ?)')->execute([$u['id'], 'create']);
        self::assertSame(1, Access::freeCreationsThisMonth((int) $u['id']));
        // Al vencer el bonus vuelve el plan gratuito.
        $end = $this->withBonus(null, $future, 'pareja', '2020-01-01 00:00:00');
        self::assertSame(Access::FREE_MONTHLY_HTML_UPLOADS, Access::htmlUploadUsage($end)['allowed']);
        self::assertTrue(Access::isFreePlan($end));
    }

    public function testApplyTierPurchaseIgnoresBonusAndKeepsIt(): void
    {
        $future = '2099-01-01 00:00:00';
        $u = $this->withBonus(null, $future, 'pareja', $future);
        self::$pdo->beginTransaction();
        Access::applyTierPurchase(self::$pdo, (int) $u['id'], $this->tierId('romantico'));   // inferior al bonus: se compra igual
        self::$pdo->commit();
        $row = $this->row('users', (int) $u['id']);
        self::assertSame($this->tierId('romantico'), (int) $row['membership_tier_id']);
        self::assertSame($this->tierId('pareja'), (int) $row['bonus_tier_id'], 'el bonus sigue intacto');
        self::assertSame('pareja', $this->slug(Access::userTier($row)));
    }

    public function testSessionUserRowIncludesBonusColumns(): void
    {
        self::assertStringContainsString('bonus_tier_id, bonus_tier_expires_at', (string) file_get_contents(dirname(__DIR__, 2) . '/src/bootstrap.php'));
    }
}
