<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cupones en recargas de monedas: alcance y descuento sobre el precio (las monedas no cambian). */
final class PromoCoinsTest extends TestCase
{
    public function testAlcanceDeLosCupones(): void
    {
        $all = ['scope' => 'all', 'tier_id' => null];
        $tiers = ['scope' => 'tiers', 'tier_id' => null];
        $tpls = ['scope' => 'templates', 'tier_id' => null];
        $coins = ['scope' => 'coins', 'tier_id' => null];

        $this->assertTrue(Payments::promoApplies($all, null, false, true), 'all cubre monedas');
        $this->assertTrue(Payments::promoApplies($coins, null, false, true));
        $this->assertFalse(Payments::promoApplies($coins, 1, false, false), 'coins no cubre membresías');
        $this->assertFalse(Payments::promoApplies($coins, null, true, false), 'coins no cubre plantillas');
        $this->assertFalse(Payments::promoApplies($tiers, null, false, true), 'tiers no cubre monedas');
        $this->assertFalse(Payments::promoApplies($tpls, null, true, true), 'templates no cubre monedas');
        $this->assertTrue(Payments::promoApplies($tiers, 1, false), 'sin cambios para membresías');
        $this->assertTrue(Payments::promoApplies($tpls, null, true), 'sin cambios para plantillas');
    }

    public function testElDescuentoRebajaElPrecioDelPaquete(): void
    {
        foreach (Coins::PACKS_CENTS as $cents) {
            $this->assertSame((int) round($cents * 0.8), Payments::discountedCents($cents, 20));
            $this->assertGreaterThanOrEqual(WOMPI_MIN_AMOUNT_IN_CENTS, Payments::discountedCents($cents, 99));
            $this->assertSame(0, Payments::discountedCents($cents, 100), 'el 100 % da 0: checkout lo rechaza para monedas');
        }
    }
}
