<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Acciones de admin sobre usuarios y páginas (BD *_test). */
final class UserAdminTest extends TestCase
{
    private static PDO $pdo;
    private int $admin;

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
        self::$pdo->exec('DELETE FROM admin_audit');
        self::$pdo->exec('DELETE FROM users');
        $this->admin = $this->user('admin@x.test', 1);
        $_SESSION = [];
    }

    private function user(string $email, int $isAdmin = 0): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)')->execute([$email, 'x', $isAdmin]);
        return (int) self::$pdo->lastInsertId();
    }

    private function tier(string $slug): int
    {
        return (int) self::$pdo->query("SELECT id FROM membership_tiers WHERE slug = '$slug'")->fetchColumn();
    }

    private function site(int $uid, string $slug, ?string $exp = null): int
    {
        $tpl = (int) self::$pdo->query('SELECT id FROM templates LIMIT 1')->fetchColumn();
        if ($tpl === 0) {
            self::$pdo->exec("INSERT INTO templates (slug, name, file) VALUES ('t-x', 'T', 't.html')");
            $tpl = (int) self::$pdo->lastInsertId();
        }
        self::$pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data, expires_at) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $tpl, $slug, '{}', $exp]);
        return (int) self::$pdo->lastInsertId();
    }

    public function testAdjustCoins(): void
    {
        $u = $this->user('a@x.test');
        $this->assertSame(50, AdminUsers::adjustCoins($u, 50, 'bono', $this->admin));
        $this->assertSame(20, AdminUsers::adjustCoins($u, -30, 'error', $this->admin));
        $tx = Coins::history($u);
        $this->assertSame('admin_adjust', $tx[0]['reason']);
        $this->assertSame(20, (int) $tx[0]['balance_after']);
        $this->assertCount(2, $tx);
        $this->assertCount(2, AdminUsers::auditOf($u));
    }

    public function testAdjustCoinsRejectsNegativeBalanceAndBadInput(): void
    {
        $u = $this->user('a@x.test');
        foreach ([[-1, 'r'], [0, 'r'], [100001, 'r'], [5, ''], [5, str_repeat('x', 201)]] as [$d, $r]) {
            try {
                AdminUsers::adjustCoins($u, $d, $r, $this->admin);
                $this->fail("debió fallar: $d/$r");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Coins::balance($u));
    }

    public function testGrantPlanExtendsDoesNotDowngradeForceBonus(): void
    {
        $u = $this->user('a@x.test');
        $par = $this->tier('pareja');
        $rom = $this->tier('romantico');
        $first = AdminUsers::grantPlan($u, $par, 1, 'n', $this->admin);
        $second = AdminUsers::grantPlan($u, $par, 1, 'n', $this->admin);
        $this->assertGreaterThan(strtotime($first), strtotime($second));
        $this->assertSame(0, Coins::balance($u), 'sin bono por defecto');
        try {
            AdminUsers::grantPlan($u, $rom, 1, 'n', $this->admin);
            $this->fail('no debe degradar');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        AdminUsers::grantPlan($u, $rom, 1, 'n', $this->admin, true, true);
        $row = AdminUsers::find($u);
        $this->assertSame($rom, (int) $row['membership_tier_id']);
        $this->assertSame(10, Coins::balance($u), 'bono del plan romántico');
    }

    public function testRemovePlan(): void
    {
        $u = $this->user('a@x.test');
        AdminUsers::grantPlan($u, $this->tier('eterno'), 1, 'n', $this->admin);
        AdminUsers::removePlan($u, 'fin', $this->admin);
        $row = AdminUsers::find($u);
        $this->assertNull($row['membership_tier_id']);
        $this->assertNull($row['membership_expires_at']);
        $this->assertSame(0, (int) $row['is_premium']);
    }

    public function testSuspensionBlocksSessionUser(): void
    {
        $u = $this->user('a@x.test');
        $_SESSION = ['uid' => $u, 'pwv' => 'x'];   // 'x' = password_hash con el que user() crea la fila
        $this->assertSame($u, (int) load_session_user()['id']);
        AdminUsers::setSuspended($u, true, 'abuso', $this->admin);
        $this->assertNull(load_session_user());
        $this->assertSame([], $_SESSION);
        AdminUsers::setSuspended($u, false, 'ok', $this->admin);
        $_SESSION = ['uid' => $u, 'pwv' => 'x'];
        $this->assertNotNull(load_session_user());
    }

    /** Cambiar la contraseña (aquí, en BD) revoca cualquier sesión abierta con el hash anterior. */
    public function testPasswordChangeInvalidatesOtherSessions(): void
    {
        $u = $this->user('a@x.test');
        $_SESSION = ['uid' => $u, 'pwv' => 'x'];
        $this->assertNotNull(load_session_user(), 'la sesión con el hash vigente autentica');

        self::$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute(['y', $u]);
        $this->assertNull(load_session_user(), 'una sesión con el hash antiguo queda revocada');
        $this->assertSame([], $_SESSION, 'la sesión revocada se limpia como una suspensión');

        $_SESSION = ['uid' => $u, 'pwv' => 'y'];
        $this->assertNotNull(load_session_user(), 'una sesión con el hash nuevo sigue autenticando');
    }

    public function testCannotSuspendOrDeleteAdminOrSelf(): void
    {
        $other = $this->user('b@x.test', 1);
        foreach ([fn() => AdminUsers::setSuspended($other, true, 'r', $this->admin),
                  fn() => AdminUsers::setSuspended($this->admin, true, 'r', $this->admin),
                  fn() => AdminUsers::deleteAccount($other, 'b@x.test', 'r', $this->admin),
                  fn() => AdminUsers::deleteAccount($this->admin, 'admin@x.test', 'r', $this->admin)] as $f) {
            try {
                $f();
                $this->fail('debió fallar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeleteAccountCascadeAndEmailConfirmation(): void
    {
        $u = $this->user('del@x.test');
        Coins::credit($u, 5, 'topup');
        $this->site($u, 'abcdef1');
        try {
            AdminUsers::deleteAccount($u, 'otro@x.test', 'r', $this->admin);
            $this->fail('correo distinto');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        AdminUsers::deleteAccount($u, 'DEL@x.test', 'solicitud', $this->admin);
        $this->assertNull(AdminUsers::find($u));
        foreach (['user_sites', 'coin_transactions'] as $t) {
            $this->assertSame(0, (int) self::$pdo->query("SELECT COUNT(*) FROM $t WHERE user_id = $u")->fetchColumn());
        }
        $this->assertStringContainsString('del@x.test', (string) self::$pdo->query("SELECT detail FROM admin_audit WHERE action = 'user.delete'")->fetchColumn());
    }

    public function testSearchFiltersAndLikeEscape(): void
    {
        $a = $this->user('ana_1@x.test');
        $b = $this->user('anaX1@x.test');
        $c = $this->user('carl@x.test');
        AdminUsers::grantPlan($a, $this->tier('pareja'), 1, 'n', $this->admin);
        AdminUsers::setSuspended($c, true, 'r', $this->admin);
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, status) VALUES (?, 'r1', 100, 'PENDING')")->execute([$b]);

        $ids = static fn(array $r): array => array_map(static fn($x): int => (int) $x['id'], $r['rows']);
        $this->assertSame([$a], $ids(AdminUsers::search('ana_1', 'all', 1)), '_ es literal');
        $this->assertSame([], $ids(AdminUsers::search('%', 'all', 1)));
        $this->assertSame([$c], $ids(AdminUsers::search((string) $c, 'all', 1)));
        $this->assertSame([$a], $ids(AdminUsers::search('', 'plan', 1)));
        $this->assertSame([$c], $ids(AdminUsers::search('', 'suspended', 1)));
        $this->assertSame([$this->admin], $ids(AdminUsers::search('', 'admins', 1)));
        $this->assertSame([$b], $ids(AdminUsers::search('', 'pending', 1)));
        $this->assertCount(3, AdminUsers::search('', 'free', 1)['rows']);
        $row = AdminUsers::search('ana_1', 'all', 1)['rows'][0];
        $this->assertSame('Pareja', $row['plan_name']);
    }

    public function testPagesSearchAndDelete(): void
    {
        $u = $this->user('own@x.test');
        $s1 = $this->site($u, 'act12345');
        $this->site($u, 'old12345', '2000-01-01 00:00:00');
        $this->assertCount(1, AdminUsers::pages('', 'active', 1)['rows']);
        $this->assertCount(1, AdminUsers::pages('', 'expired', 1)['rows']);
        $this->assertCount(2, AdminUsers::pages('own@', 'all', 1)['rows']);
        AdminUsers::deletePage($s1, 'contenido', $this->admin);
        $this->assertCount(1, AdminUsers::pages('', 'all', 1)['rows']);
        $this->assertNotEmpty(AdminUsers::auditOf($u));
    }
}
