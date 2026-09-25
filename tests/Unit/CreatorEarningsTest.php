<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatorsTestCase.php';

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Reparto de ingresos: %, redondeo, autocompra, coins vs cupo, renovación, idempotencia, settle, libro y concurrencia. */
#[RunTestsInSeparateProcesses]
final class CreatorEarningsTest extends CreatorsTestCase
{
    public function testCoinsPurchasePaysShareAndBalancesMatchLedger(): void
    {
        $c = $this->user('Creadora');
        $b = $this->user('Compradora', 100);
        $t = $this->utpl((int) $c['id'], 'approved', 25);
        $r = $this->buy((int) $b['id'], $t);
        self::assertArrayHasKey('slug', $r);
        self::assertSame(75, $this->coins((int) $b['id']));
        self::assertSame(7, $this->coins((int) $c['id']), 'floor(25 * 30 / 100) = 7 y ya se liquidó tras el commit');
        self::assertSame('paid', self::$pdo->query('SELECT status FROM template_earnings')->fetchColumn());
        self::assertSame($this->coins((int) $c['id']), $this->ledger((int) $c['id']), 'el saldo cuadra con el libro');
        self::assertSame('creator_share', self::$pdo->query('SELECT reason FROM coin_transactions WHERE user_id = ' . (int) $c['id'])->fetchColumn());
    }

    public function testSelfPurchasePaysNothing(): void
    {
        $c = $this->user('Solo', 100);
        $t = $this->utpl((int) $c['id'], 'approved', 20);
        self::assertArrayHasKey('slug', $this->buy((int) $c['id'], $t));
        self::assertSame(80, $this->coins((int) $c['id']));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM template_earnings'));
    }

    public function testShareConfigAndRounding(): void
    {
        $cfg = Creators::DEFAULTS;
        $cfg['share_pct'] = 10;
        self::assertSame([], Creators::saveConfig($cfg));
        $c = $this->user('Redondea');
        $b = $this->user('Compra', 100);
        $t = $this->utpl((int) $c['id'], 'approved', 9);   // floor(0.9) = 0: sin ganancia
        $this->buy((int) $b['id'], $t);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM template_earnings'));
        $t2 = $this->utpl((int) $c['id'], 'approved', 19);  // floor(1.9) = 1
        $this->buy((int) $b['id'], $t2);
        self::assertSame(1, $this->coins((int) $c['id']));
    }

    public function testQuotaUseIsPaidOnNominalPriceOnlyWhenEnabled(): void
    {
        $c = $this->user('Cupo');
        $m = $this->user('Miembro', 0, 'pareja');
        $t = $this->utpl((int) $c['id'], 'approved', 40, true);
        self::assertArrayHasKey('slug', $this->buy((int) $m['id'], $t));
        self::assertSame(0, $this->coins((int) $m['id']), 'lo cubre el cupo de la membresía');
        self::assertSame(12, $this->coins((int) $c['id']), '30 % del valor nominal (40) financiado por la plataforma');
        self::assertSame('quota', self::$pdo->query('SELECT kind FROM template_earnings')->fetchColumn());
        // Otra página con la misma plantilla el mismo mes: no consume cupo nuevo, no vuelve a pagar.
        $this->buy((int) $m['id'], $t);
        self::assertSame(12, $this->coins((int) $c['id']));

        $cfg = Creators::DEFAULTS;
        $cfg['share_on_quota_unlock'] = false;
        Creators::saveConfig($cfg);
        $c2 = $this->user('Cupo2');
        $m2 = $this->user('Miembro2', 0, 'pareja');
        $t2 = $this->utpl((int) $c2['id'], 'approved', 40, true);
        $this->buy((int) $m2['id'], $t2);
        self::assertSame(0, $this->coins((int) $c2['id']), 'con la opción desactivada no se paga por cupo');
    }

    public function testQuotaExhaustedFallsBackToCoinsPayment(): void
    {
        $c = $this->user('Cae');
        $m = $this->user('Agota', 100, 'romantico');   // cupo 1
        $t1 = $this->utpl((int) $c['id'], 'approved', 20, true);
        $t2 = $this->utpl((int) $c['id'], 'approved', 20, true);
        $this->buy((int) $m['id'], $t1);   // cupo
        $this->buy((int) $m['id'], $t2);   // monedas
        self::assertSame(80, $this->coins((int) $m['id']));
        self::assertSame(12, $this->coins((int) $c['id']), '6 por cupo + 6 por monedas');
    }

    public function testRenewPaysAndIsIdempotentByRef(): void
    {
        $c = $this->user('Renueva');
        $b = $this->user('Compra', 200, 'pareja');
        $t = $this->utpl((int) $c['id'], 'approved', 20);
        $r = $this->buy((int) $b['id'], $t);
        $siteId = $this->scalar("SELECT id FROM user_sites WHERE slug = '" . $r['slug'] . "'");
        self::assertNull(Sites::renew($this->row('users', (int) $b['id']), $siteId));
        self::assertSame(12, $this->coins((int) $c['id']));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM template_earnings'));
        // Idempotencia: el mismo ref no se vuelve a registrar.
        $ctx = Creators::saleContext(self::$pdo, (int) $t['id']);
        self::assertNotNull($ctx);
        self::$pdo->beginTransaction();
        self::assertNull(CreatorEarnings::record(self::$pdo, $ctx, (int) $b['id'], $siteId, 'create:' . $siteId, 'coins', 20));
        self::$pdo->commit();
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM template_earnings'));
    }

    public function testSettleIsIdempotentAndRetryable(): void
    {
        $c = $this->user('Liquida');
        $b = $this->user('Compra', 0);
        $t = $this->utpl((int) $c['id'], 'approved', 20);
        $ctx = Creators::saleContext(self::$pdo, (int) $t['id']);
        self::$pdo->beginTransaction();
        CreatorEarnings::record(self::$pdo, $ctx, (int) $b['id'], 1, 'create:1', 'coins', 20);
        CreatorEarnings::record(self::$pdo, $ctx, (int) $b['id'], 2, 'create:2', 'coins', 20);
        self::$pdo->commit();
        self::assertSame(12, CreatorEarnings::pendingCoins((int) $c['id']));
        self::assertSame(12, CreatorEarnings::settle((int) $c['id']));
        self::assertSame(0, CreatorEarnings::settle((int) $c['id']), 'segunda liquidación: nada que pagar');
        self::assertSame(12, $this->coins((int) $c['id']));
        self::assertSame(12, $this->ledger((int) $c['id']));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM coin_transactions WHERE reason = 'creator_share'"), 'un solo asiento por liquidación');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM template_earnings WHERE status = 'pending'"));
        self::assertSame(0, CreatorEarnings::settleAll());
    }

    public function testNotApprovedTemplateNeverEarnsAndCannotBeBought(): void
    {
        $c = $this->user('Pend');
        $b = $this->user('Compra', 100);
        foreach (['pending', 'rejected', 'withdrawn'] as $status) {
            $t = $this->utpl((int) $c['id'], $status, 20);
            $r = $this->buy((int) $b['id'], $t);
            self::assertArrayHasKey('error', $r, "estado $status");
        }
        self::assertSame(100, $this->coins((int) $b['id']), 'no se cobró nada');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM user_sites'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM template_earnings'));
    }

    public function testMutualPurchasesDoNotDeadlock(): void
    {
        $a = $this->user('Alfa', 100);
        $b = $this->user('Beta', 100);
        $ta = $this->utpl((int) $a['id'], 'approved', 20);
        $tb = $this->utpl((int) $b['id'], 'approved', 20);
        $ctxA = Creators::saleContext(self::$pdo, (int) $ta['id']);
        $ctxB = Creators::saleContext(self::$pdo, (int) $tb['id']);
        $p2 = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), (int) getenv('DB_PORT'), getenv('DB_NAME')),
            (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([self::$pdo, $p2] as $p) {
            $p->exec('SET SESSION innodb_lock_wait_timeout = 3');
        }
        // Dos transacciones abiertas a la vez: cada comprador bloquea SU fila y registra ganancia para el otro.
        self::$pdo->beginTransaction();
        $p2->beginTransaction();
        self::$pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$a['id']]);
        $p2->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$b['id']]);
        CreatorEarnings::record(self::$pdo, $ctxB, (int) $a['id'], 1, 'create:1', 'coins', 20);   // A compra la de B
        CreatorEarnings::record($p2, $ctxA, (int) $b['id'], 2, 'create:2', 'coins', 20);          // B compra la de A
        self::$pdo->commit();
        $p2->commit();
        self::assertSame(6, CreatorEarnings::settle((int) $a['id']));
        self::assertSame(6, CreatorEarnings::settle((int) $b['id']));
    }
}
