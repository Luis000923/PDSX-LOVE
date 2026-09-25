<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Premios del top: cierre de mes idempotente, no retroactividad, hitos, integración con Payments y configuración. BD `*_test`. */
// Proceso aparte: se ordena antes que DatabaseTest (que recrea el esquema) y no debe dejarle un db() ya abierto.
#[RunTestsInSeparateProcesses]
final class AwardsTest extends TestCase
{
    private static PDO $pdo;

    protected function setUp(): void
    {
        try {
            self::$pdo = db();   // aquí y no en setUpBeforeClass: ese método corre también en el proceso padre
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
        foreach (['payments', 'coin_transactions', 'award_grants', 'user_badges', 'awards_closed_months', 'users'] as $t) {
            self::$pdo->exec("DELETE FROM $t");
        }
        self::$pdo->exec("DELETE FROM settings WHERE `key` = 'awards_config'");
        Awards::putSetting(Awards::LAUNCH_KEY, '2026-08');
        Awards::flushCache();
    }

    protected function tearDown(): void
    {
        self::$pdo->exec("DELETE FROM settings WHERE `key` = 'awards_config'");
    }

    private function user(string $email): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, 'x']);
        return (int) self::$pdo->lastInsertId();
    }

    private function pay(int $uid, int $cents, string $at, string $method = 'WOMPI'): int
    {
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, method, fulfilled_at) VALUES (?, ?, ?, 'APPROVED', ?, ?)")
            ->execute([$uid, 'R-' . bin2hex(random_bytes(6)), $cents, $method, $at]);
        return (int) self::$pdo->lastInsertId();
    }

    private function coins(int $uid): int
    {
        return (int) self::$pdo->query("SELECT coins FROM users WHERE id = $uid")->fetchColumn();
    }

    private function ledger(int $uid): int
    {
        return (int) self::$pdo->query("SELECT COALESCE(SUM(delta), 0) FROM coin_transactions WHERE user_id = $uid")->fetchColumn();
    }

    private function now(string $utc): int
    {
        return (int) (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('U');
    }

    /** @return list<array<string,mixed>> */
    private function grants(): array
    {
        return self::$pdo->query('SELECT user_id, grant_key, kind, coins FROM award_grants ORDER BY grant_key, user_id')->fetchAll();
    }

    public function testMonthClosePaysTopThreeOnceWithBadgesAndLedger(): void
    {
        [$a, $b, $c, $d, $e] = [$this->user('a@x.test'), $this->user('b@x.test'), $this->user('c@x.test'), $this->user('d@x.test'), $this->user('e@x.test')];
        $this->pay($a, 900, '2026-08-10 12:00:00');
        $this->pay($b, 800, '2026-08-11 12:00:00');
        $this->pay($c, 700, '2026-08-12 12:00:00');
        $this->pay($d, 600, '2026-08-13 12:00:00');    // 4.º: sin premio
        $this->pay($e, 99, '2026-08-13 12:00:00');     // bajo el mínimo de $1.00
        $now = $this->now('2026-09-03 12:00:00');
        self::assertSame(['2026-08'], Awards::pendingMonths(self::$pdo, $now));
        $r = Awards::closePendingMonths(self::$pdo, $now);
        self::assertSame(['closed' => ['2026-08'], 'granted' => 3], $r);
        self::assertSame([100, 50, 25], [$this->coins($a), $this->coins($b), $this->coins($c)]);
        self::assertSame(0, $this->coins($d));
        self::assertSame(100, $this->ledger($a));
        self::assertSame('award_month', self::$pdo->query("SELECT reason FROM coin_transactions WHERE user_id = $a")->fetchColumn());
        self::assertSame('month:2026-08:1', self::$pdo->query("SELECT ref FROM coin_transactions WHERE user_id = $a")->fetchColumn());
        self::assertSame('mecenas_1', self::$pdo->query("SELECT badge_key FROM user_badges WHERE user_id = $a")->fetchColumn());
        self::assertSame('mecenas_3', self::$pdo->query("SELECT badge_key FROM user_badges WHERE user_id = $c")->fetchColumn());
        // Segunda llamada: nada.
        self::assertSame(['closed' => [], 'granted' => 0], Awards::closePendingMonths(self::$pdo, $now));
        self::assertSame(100, $this->coins($a));
        self::assertCount(3, $this->grants());
    }

    public function testConcurrentCloseSimulationNeverDoublePays(): void
    {
        $a = $this->user('a@x.test');
        $this->pay($a, 500, '2026-08-10 12:00:00');
        self::assertSame(1, Awards::closeMonth(self::$pdo, '2026-08'));
        self::assertSame(-1, Awards::closeMonth(self::$pdo, '2026-08'), 'el segundo proceso ve el mes ya cerrado');
        // Aunque alguien borre la marca del mes, el UNIQUE del libro impide pagar dos veces.
        self::$pdo->exec('DELETE FROM awards_closed_months');
        self::assertSame(0, Awards::closeMonth(self::$pdo, '2026-08'));
        self::assertSame(100, $this->coins($a));
        self::assertSame(100, $this->ledger($a));
    }

    public function testNoRetroactiveAwardsBeforeLaunch(): void
    {
        Awards::putSetting(Awards::LAUNCH_KEY, '2026-09');
        $a = $this->user('a@x.test');
        $this->pay($a, 5000, '2026-08-10 12:00:00');
        $this->pay($a, 700, '2026-09-10 12:00:00');
        $r = Awards::closePendingMonths(self::$pdo, $this->now('2026-10-02 12:00:00'));
        self::assertSame(['2026-09'], $r['closed']);
        self::assertSame([], self::$pdo->query("SELECT 1 FROM award_grants WHERE grant_key LIKE 'month:2026-08%'")->fetchAll());
        self::assertSame(['month:2026-09:1'], array_column($this->grants(), 'grant_key'));
    }

    public function testCurrentMonthIsNotClosedAndPromoDoesNotCount(): void
    {
        $a = $this->user('a@x.test');
        $this->pay($a, 500, '2026-09-10 12:00:00');
        $this->pay($a, 500, '2026-08-10 12:00:00', 'PROMO');
        $r = Awards::closePendingMonths(self::$pdo, $this->now('2026-09-20 12:00:00'));
        self::assertSame(['2026-08'], $r['closed']);
        self::assertSame(0, $r['granted'], 'agosto solo tenía un cupón');
        self::assertSame([], $this->grants());
    }

    public function testMilestonesGrantedOnceViaPaymentsFulfill(): void
    {
        $u = $this->user('a@x.test');
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, method, coins) VALUES (?, 'R1', 1000, 'APPROVED', 'WOMPI', 100)")->execute([$u]);
        $pid = (int) self::$pdo->lastInsertId();
        $a = Payments::fulfill(self::$pdo, $pid, 'test');
        $b = Payments::fulfill(self::$pdo, $pid, 'test');
        self::assertTrue($a['ok'] && !$a['already'] && $b['already']);
        self::assertSame(110, $this->coins($u), '100 de la recarga + 10 del hito de $10');
        self::assertSame(110, $this->ledger($u), 'saldo y libro coherentes');
        self::assertSame(['milestone:1000'], array_column($this->grants(), 'grant_key'));
        self::assertSame('apoyo_bronce', self::$pdo->query("SELECT badge_key FROM user_badges WHERE user_id = $u")->fetchColumn());
        self::assertSame(0, Awards::evaluateMilestones(self::$pdo, $u), 'evaluar de nuevo no duplica');
        self::assertSame(110, $this->coins($u));
    }

    public function testFulfillCrossingSeveralMilestonesAndFreePromoDoesNot(): void
    {
        $u = $this->user('a@x.test');
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, method, coins) VALUES (?, 'R2', 3000, 'APPROVED', 'WOMPI', 300)")->execute([$u]);
        Payments::fulfill(self::$pdo, (int) self::$pdo->lastInsertId(), 'test');
        self::assertSame(300 + 10 + 30, $this->coins($u));
        self::assertSame(['milestone:1000', 'milestone:2500'], array_column($this->grants(), 'grant_key'));
        $v = $this->user('v@x.test');
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status, method, coins) VALUES (?, 'R3', 0, 'APPROVED', 'PROMO', 1)")->execute([$v]);
        Payments::fulfill(self::$pdo, (int) self::$pdo->lastInsertId(), 'test');
        self::assertSame([], self::$pdo->query("SELECT 1 FROM award_grants WHERE user_id = $v")->fetchAll());
    }

    public function testLazyMilestonesForPastSpenders(): void
    {
        $u = $this->user('a@x.test');
        $this->pay($u, 5000, '2026-07-01 12:00:00');
        self::assertSame(3, Awards::evaluateMilestones(self::$pdo, $u));
        self::assertSame(10 + 30 + 70, $this->coins($u));
        self::assertSame(0, Awards::evaluateMilestones(self::$pdo, $u));
        self::assertSame([1000, 2500, 5000], Awards::grantedMilestones($u));
        self::assertSame(['apoyo_oro', 'apoyo_plata', 'apoyo_bronce'], array_column(Awards::userBadges($u), 'key'));
        self::assertFalse(self::$pdo->inTransaction());
    }

    public function testProgress(): void
    {
        self::assertSame(['cents' => 1000, 'coins' => 10], Awards::progress(0)['next']);
        self::assertSame(50, Awards::progress(500)['pct']);
        self::assertSame(500, Awards::progress(500)['left']);
        self::assertSame(2500, Awards::progress(1000)['next']['cents'] ?? 0);
        self::assertNull(Awards::progress(99999)['next']);
    }

    public function testConfigValidation(): void
    {
        $ok = ['top_n' => '3', 'month_coins' => ['100', 50, 25], 'min_month_cents' => 100, 'milestones' => [['cents' => 1000, 'coins' => 5], ['cents' => 2000, 'coins' => 0]]];
        self::assertNotNull(Awards::validateConfig($ok)['config']);
        $bad = [
            'top_n 0'          => ['top_n' => 0] + $ok,
            'top_n 11'         => ['top_n' => 11, 'month_coins' => array_fill(0, 11, 1)] + $ok,
            'top_n texto'      => ['top_n' => 'x'] + $ok,
            'monedas 10001'    => ['month_coins' => [10001, 1, 1]] + $ok,
            'monedas negativas' => ['month_coins' => [-1, 1, 1]] + $ok,
            'faltan monedas'   => ['month_coins' => [1, 1]] + $ok,
            'mínimo 0'         => ['min_month_cents' => 0] + $ok,
            'hitos repetidos'  => ['milestones' => [['cents' => 1000, 'coins' => 1], ['cents' => 1000, 'coins' => 1]]] + $ok,
            'hitos decrecen'   => ['milestones' => [['cents' => 2000, 'coins' => 1], ['cents' => 1000, 'coins' => 1]]] + $ok,
            'demasiados hitos' => ['milestones' => array_map(static fn(int $i): array => ['cents' => ($i + 1) * 100, 'coins' => 1], range(0, 6))] + $ok,
            'hito decimal'     => ['milestones' => [['cents' => '10.5', 'coins' => 1]]] + $ok,
        ];
        foreach ($bad as $name => $raw) {
            $v = Awards::validateConfig($raw);
            self::assertNull($v['config'], $name);
            self::assertNotSame([], $v['errors'], $name);
        }
    }

    public function testSaveConfigChangesBehaviourAndInvalidFallsBackToDefaults(): void
    {
        self::assertSame(Awards::DEFAULTS, Awards::config());
        self::assertSame([], Awards::saveConfig(['top_n' => 2, 'month_coins' => [7, 3], 'min_month_cents' => 500, 'milestones' => [['cents' => 300, 'coins' => 1]]]));
        $a = $this->user('a@x.test');
        $b = $this->user('b@x.test');
        $this->pay($a, 600, '2026-08-10 12:00:00');
        $this->pay($b, 499, '2026-08-11 12:00:00');
        Awards::closeMonth(self::$pdo, '2026-08');
        self::assertSame(7, $this->coins($a));
        self::assertSame(0, $this->coins($b));
        Awards::putSetting(Awards::CONFIG_KEY, '{"top_n": 99}');
        Awards::flushCache();
        self::assertSame(Awards::DEFAULTS, Awards::config());
        self::assertNotSame([], Awards::saveConfig(['top_n' => 99]));
    }
}
