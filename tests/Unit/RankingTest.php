<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Top de donadores: ventanas del mes (El Salvador), exclusiones, desempates y privacidad. BD `*_test`. */
final class RankingTest extends TestCase
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
        foreach (['payments', 'coin_transactions', 'award_grants', 'user_badges', 'users'] as $t) {
            self::$pdo->exec("DELETE FROM $t");
        }
    }

    private function user(string $email, ?string $alias = null, int $show = 0, int $suspended = 0): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, display_name, show_in_rankings, is_suspended) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, 'x', $alias, $show, $suspended]);
        return (int) self::$pdo->lastInsertId();
    }

    private function pay(int $uid, int $cents, ?string $at, string $status = 'APPROVED', string $method = 'WOMPI'): void
    {
        self::$pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, status, method, fulfilled_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, 'R-' . bin2hex(random_bytes(6)), $cents, $status, $method, $at]);
    }

    private function ts(string $utc): int
    {
        return (int) (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('U');
    }

    public function testMonthWindowEdgeElSalvador(): void
    {
        $u = $this->user('a@x.test');
        $this->pay($u, 500, '2026-09-01 05:59:59');   // 31 ago 23:59:59 en El Salvador
        $this->pay($u, 300, '2026-09-01 06:00:00');   // 1 sep 00:00:00
        $sep = Ranking::standing($u, 'mes', $this->ts('2026-09-15 12:00:00'));
        $aug = Ranking::standing($u, 'mes', $this->ts('2026-08-15 12:00:00'));
        self::assertSame(300, $sep['cents'] ?? null);
        self::assertSame(500, $aug['cents'] ?? null);
        self::assertSame(800, Ranking::standing($u, 'historico')['cents'] ?? null);
    }

    public function testExcludesNonQualifyingPayments(): void
    {
        $u = $this->user('a@x.test');
        $at = '2026-09-10 12:00:00';
        $this->pay($u, 100, $at);                              // cuenta
        $this->pay($u, 0, $at, 'APPROVED', 'PROMO');
        $this->pay($u, 999, $at, 'APPROVED', 'PROMO');
        $this->pay($u, 0, $at);
        $this->pay($u, 999, null);                             // no cumplido
        foreach (['PENDING', 'DECLINED', 'VOIDED', 'ERROR'] as $s) {
            $this->pay($u, 999, $at, $s);
        }
        $this->pay($u, 200, $at, 'APPROVED', 'MANUAL');        // cuenta
        self::assertSame(300, Ranking::standing($u, 'historico')['cents'] ?? null);
        self::assertCount(1, Ranking::publicTop('historico'));
    }

    public function testTieBreakersAndSuspended(): void
    {
        $a = $this->user('a@x.test');
        $b = $this->user('b@x.test');
        $c = $this->user('c@x.test');
        $d = $this->user('d@x.test');
        $s = $this->user('s@x.test', null, 0, 1);
        $this->pay($s, 99999, '2026-09-02 10:00:00');
        $this->pay($a, 500, '2026-09-05 10:00:00');
        $this->pay($b, 500, '2026-09-03 10:00:00');   // mismo total, pago más antiguo: gana
        $this->pay($c, 500, '2026-09-03 10:00:00');   // empate total: id menor gana (b < c)
        $this->pay($d, 900, '2026-09-20 10:00:00');
        $now = $this->ts('2026-09-25 12:00:00');
        self::assertSame(1, Ranking::standing($d, 'mes', $now)['pos'] ?? 0);
        self::assertSame(2, Ranking::standing($b, 'mes', $now)['pos'] ?? 0);
        self::assertSame(3, Ranking::standing($c, 'mes', $now)['pos'] ?? 0);
        self::assertSame(4, Ranking::standing($a, 'mes', $now)['pos'] ?? 0);
        self::assertNull(Ranking::standing($s, 'mes', $now), 'suspendido fuera');
        [$from, $to] = Ranking::window('mes', $now);
        self::assertSame([$d, $b, $c, $a], array_column(Ranking::rows($from, $to, 10), 'user_id'));
    }

    public function testStandingOutsideTopWithoutExposingOthers(): void
    {
        $me = $this->user('yo@x.test');
        for ($i = 0; $i < 12; $i++) {
            $this->pay($this->user("u$i@x.test"), 1000 + $i, '2026-09-10 12:00:00');
        }
        $this->pay($me, 100, '2026-09-11 12:00:00');
        $st = Ranking::standing($me, 'historico');
        self::assertSame(13, $st['pos'] ?? 0);
        self::assertSame(['pos', 'cents'], array_keys((array) $st));
    }

    public function testPrivacyNeverLeaksEmailAndAliasNeedsOptIn(): void
    {
        $vis = $this->user('secreto1@x.test', 'Ana Luz', 1);
        $off = $this->user('secreto2@x.test', 'Pedro', 0);      // alias pero sin opt-in
        $bad = $this->user('secreto3@x.test', 'http://x.co', 1); // alias inválido guardado a mano
        $non = $this->user('secreto4@x.test');
        foreach ([$vis, $off, $bad, $non] as $i => $u) {
            $this->pay($u, 1000 - $i * 100, '2026-09-10 12:00:00');
        }
        $top = Ranking::publicTop('historico');
        self::assertSame(['Ana Luz', null, null, null], array_column($top, 'name'));
        $json = (string) json_encode($top);
        self::assertStringNotContainsString('secreto', $json);
        self::assertStringNotContainsString('@', $json);
        self::assertStringNotContainsString('user_id', $json);
        foreach ($top as $r) {
            self::assertSame(['pos', 'name', 'cents', 'badges'], array_keys($r));
        }
    }

    public function testAliasValidation(): void
    {
        foreach (['ab', str_repeat('a', 31), 'a@b.com', '<b>hola</b>', 'mira www.x.com', 'https://a.b', '   ', '123', 'hola!', 'ana&luz'] as $bad) {
            self::assertNotNull(Ranking::validateAlias($bad)['error'], $bad);
        }
        self::assertSame('José María_2.0-x', Ranking::validateAlias("  José   María_2.0-x ")['value']);
        self::assertNull(Ranking::validateAlias("  José   María_2.0-x ")['error']);
        self::assertNull(Ranking::validateAlias('Ñandú')['error']);
    }

    public function testSaveOptInAndAdminClear(): void
    {
        $u = $this->user('a@x.test');
        self::assertFalse(Ranking::saveOptIn($u, true, '')['ok'], 'activar exige alias');
        self::assertFalse(Ranking::saveOptIn($u, true, 'x@y')['ok']);
        self::assertTrue(Ranking::saveOptIn($u, true, ' Luz  Clara ')['ok']);
        self::assertSame(['alias' => 'Luz Clara', 'show' => true], Ranking::optIn($u));
        self::assertTrue(Ranking::saveOptIn($u, false, '')['ok'], 'desactivar siempre se puede');
        self::assertFalse(Ranking::optIn($u)['show']);
        Ranking::saveOptIn($u, true, 'Luz Clara');
        self::assertTrue(Ranking::clearAlias($u));
        self::assertSame(['alias' => '', 'show' => false], Ranking::optIn($u));
        self::assertFalse(Ranking::clearAlias($u));
    }
}
