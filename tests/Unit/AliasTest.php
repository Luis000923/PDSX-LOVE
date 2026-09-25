<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Alias público que se pide al registrarse: validación, nombres reservados y unicidad. */
final class AliasTest extends TestCase
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
        self::$pdo->exec('DELETE FROM users');
    }

    /** @return array<string,array{0:string}> */
    public static function reserved(): array
    {
        return ['admin' => ['Admin'], 'con símbolos' => ['A.d_m-i n'], 'marca' => ['LovePages Oficial'], 'soporte' => ['Soporte_SV'],
                'tildes' => ['Anónimo'], 'donador anónimo' => ['Donador anónimo'], 'dentro de otra' => ['MiAdministrador']];
    }

    #[DataProvider('reserved')]
    public function testReservedAliasesAreRejected(string $alias): void
    {
        self::assertNotNull(Ranking::validateAlias($alias)['error'], $alias);
    }

    /** @return array<string,array{0:string}> */
    public static function valid(): array
    {
        return ['simple' => ['Luna_Sv'], 'con espacio' => ['Amor Eterno'], 'tildes' => ['José María'], 'números' => ['Pareja2026']];
    }

    #[DataProvider('valid')]
    public function testValidAliasesAreAccepted(string $alias): void
    {
        self::assertNull(Ranking::validateAlias($alias)['error'], $alias);
    }

    public function testAliasTakenIgnoresCaseAndAccents(): void
    {
        self::$pdo->prepare("INSERT INTO users (email, password_hash, display_name) VALUES ('a@t.test', 'x', 'Josefa')")->execute();
        $id = (int) self::$pdo->lastInsertId();
        self::assertTrue(Ranking::aliasTaken('josefa'));
        self::assertTrue(Ranking::aliasTaken('JOSEFA'));
        self::assertFalse(Ranking::aliasTaken('Josefa', $id), 'el propio usuario no choca consigo mismo');
        self::assertFalse(Ranking::aliasTaken('Otra'));
    }

    public function testSaveOptInRefusesADuplicateAlias(): void
    {
        self::$pdo->exec("INSERT INTO users (email, password_hash, display_name) VALUES ('a@t.test', 'x', 'Marta')");
        self::$pdo->exec("INSERT INTO users (email, password_hash) VALUES ('b@t.test', 'x')");
        $b = (int) self::$pdo->lastInsertId();
        $r = Ranking::saveOptIn($b, true, 'marta');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('en uso', (string) $r['error']);
        self::assertTrue(Ranking::saveOptIn($b, true, 'Marta B')['ok']);
    }

    // ---------------- costo de cambiar el alias ----------------

    private function userWith(string $alias, int $coins, bool $show = true): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, display_name, show_in_rankings, coins) VALUES (?, ?, ?, ?, ?)')
            ->execute(['c' . uniqid() . '@t.test', 'x', $alias === '' ? null : $alias, $show ? 1 : 0, $coins]);
        return (int) self::$pdo->lastInsertId();
    }

    private function coins(int $id): int
    {
        return (int) self::$pdo->query('SELECT coins FROM users WHERE id = ' . $id)->fetchColumn();
    }

    private function alias(int $id): string
    {
        return (string) self::$pdo->query('SELECT COALESCE(display_name, \'\') FROM users WHERE id = ' . $id)->fetchColumn();
    }

    public function testChangingAnExistingAliasCostsCoinsAndIsLogged(): void
    {
        $cost = Ranking::aliasChangeCost();
        self::assertGreaterThan(0, $cost);
        $u = $this->userWith('Vieja', $cost + 5);
        $r = Ranking::saveOptIn($u, true, 'Nueva');
        self::assertTrue($r['ok']);
        self::assertSame($cost, $r['charged']);
        self::assertSame(5, $this->coins($u));
        self::assertSame('Nueva', $this->alias($u));
        $row = self::$pdo->query("SELECT delta, reason, ref FROM coin_transactions WHERE user_id = $u")->fetch();
        self::assertSame([-$cost, 'alias_change'], [(int) $row['delta'], $row['reason']]);
        self::assertStringContainsString('Vieja -> Nueva', (string) $row['ref']);
    }

    public function testChangeIsRefusedWithoutBalanceAndChangesNothing(): void
    {
        $u = $this->userWith('Vieja', Ranking::aliasChangeCost() - 1);
        $r = Ranking::saveOptIn($u, true, 'Nueva');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('cuesta', (string) $r['error']);
        self::assertSame('Vieja', $this->alias($u));
        self::assertSame(Ranking::aliasChangeCost() - 1, $this->coins($u));
    }

    public function testFirstAliasOfALegacyAccountIsFree(): void
    {
        $u = $this->userWith('', 0, false);
        $r = Ranking::saveOptIn($u, true, 'Primera');
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['charged']);
        self::assertSame('Primera', $this->alias($u));
    }

    public function testHidingFromTheRankingIsAlwaysFreeAndKeepsTheAlias(): void
    {
        $u = $this->userWith('Luna', 0);
        $r = Ranking::saveOptIn($u, false, '');   // sin saldo, sin alias nuevo: solo ocultar
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['charged']);
        self::assertSame('Luna', $this->alias($u), 'un alias vacío NO borra el actual');
        self::assertFalse(Ranking::optIn($u)['show']);
    }

    public function testCaseOnlyChangeIsFree(): void
    {
        $u = $this->userWith('Luna', 0);
        $r = Ranking::saveOptIn($u, true, 'LUNA');
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['charged']);
        self::assertSame('LUNA', $this->alias($u));
    }

    public function testCannotBypassTheCostByClearingTheAlias(): void
    {
        $u = $this->userWith('Luna', 0);
        Ranking::saveOptIn($u, false, '');            // intenta "borrarlo"
        $r = Ranking::saveOptIn($u, true, 'Otra');     // y ponerlo "gratis" como si fuera el primero
        self::assertFalse($r['ok'], 'sigue costando: el alias nunca queda vacío');
        self::assertSame('Luna', $this->alias($u));
    }

    public function testDuplicateAliasDoesNotChargeAnything(): void
    {
        $this->userWith('Ocupada', 0);
        $u = $this->userWith('Mia', 500);
        $r = Ranking::saveOptIn($u, true, 'ocupada');
        self::assertFalse($r['ok']);
        self::assertSame(500, $this->coins($u));
    }

    public function testZeroCostSettingMakesChangesFree(): void
    {
        Admin::setSetting('alias_change_cost', '0');
        try {
            self::assertSame(0, Ranking::aliasChangeCost());
            $u = $this->userWith('Vieja', 0);
            self::assertTrue(Ranking::saveOptIn($u, true, 'Nueva')['ok']);
            self::assertSame('Nueva', $this->alias($u));
        } finally {
            Admin::setSetting('alias_change_cost', '');
        }
        self::assertSame(Ranking::DEFAULT_ALIAS_CHANGE_COST, Ranking::aliasChangeCost());
    }
}
