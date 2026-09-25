<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Eliminar compras sueltas y limpieza total (solo administradores, con confirmaciones fuertes). */
final class AdminPurgeTest extends TestCase
{
    private static PDO $pdo;

    protected function setUp(): void
    {
        try {
            self::$pdo = db();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
    }

    private function user(bool $admin = false, string $pw = 'clave-segura', bool $hasPw = true): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, is_admin, has_password) VALUES (?, ?, ?, ?)')
            ->execute(['ap' . uniqid() . '@t.test', password_hash($pw, PASSWORD_DEFAULT), $admin ? 1 : 0, $hasPw ? 1 : 0]);
        return (int) self::$pdo->lastInsertId();
    }

    /** @return array{0:int,1:string} */
    private function payment(int $uid, string $status = 'APPROVED'): array
    {
        $ref = 'LP-' . $uid . '-' . bin2hex(random_bytes(8));
        self::$pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, currency, status, coins) VALUES (?, ?, 100, 'USD', ?, 10)")->execute([$uid, $ref, $status]);
        return [(int) self::$pdo->lastInsertId(), $ref];
    }

    private function exists(string $table, int $id): bool
    {
        return (bool) self::$pdo->query("SELECT 1 FROM $table WHERE id = $id")->fetchColumn();
    }

    public function testDeletePaymentExigeLaReferencia(): void
    {
        $admin = $this->user(true);
        [$pid, $ref] = $this->payment($this->user());
        try {
            AdminUsers::deletePayment($pid, 'LP-otra', 'limpieza', $admin);
            $this->fail('debió rechazar la referencia');
        } catch (InvalidArgumentException) {
        }
        $this->assertTrue($this->exists('payments', $pid));
        AdminUsers::deletePayment($pid, $ref, 'limpieza de prueba', $admin);
        $this->assertFalse($this->exists('payments', $pid));
    }

    public function testDeletePaymentLiberaElCupon(): void
    {
        $admin = $this->user(true);
        $u = $this->user();
        [$pid, $ref] = $this->payment($u);
        self::$pdo->prepare("INSERT INTO promos (code, discount_percent, uses) VALUES (?, 10, 1)")->execute(['DP' . strtoupper(bin2hex(random_bytes(3)))]);
        $promo = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO promo_redemptions (promo_id, user_id, payment_id) VALUES (?, ?, ?)')->execute([$promo, $u, $pid]);
        AdminUsers::deletePayment($pid, $ref, 'x', $admin);
        $this->assertSame(0, (int) self::$pdo->query("SELECT uses FROM promos WHERE id = $promo")->fetchColumn());
        $this->assertSame(0, (int) self::$pdo->query("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = $promo")->fetchColumn());
    }

    public function testPurgeRechazaFraseOCredencialIncorrecta(): void
    {
        $admin = $this->user(true, 'mi-clave');
        $victim = $this->user();
        foreach ([['eliminar todo', 'mi-clave'], ['ELIMINAR TODO', 'otra'], ['', 'mi-clave']] as [$phrase, $secret]) {
            try {
                AdminUsers::purgeAll($phrase, $secret, 'x', $admin);
                $this->fail('no debió ejecutarse');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertTrue($this->exists('users', $victim), 'nada se borra si falla una confirmación');
    }

    public function testPurgeSoloParaAdministradores(): void
    {
        $notAdmin = $this->user(false, 'clave');
        $this->expectException(InvalidArgumentException::class);
        AdminUsers::purgeAll('ELIMINAR TODO', 'clave', 'x', $notAdmin);
    }

    public function testPurgeBorraUsuariosYComprasYConservaAdmins(): void
    {
        $admin = $this->user(true, 'mi-clave');
        $other = $this->user(true);
        $u1 = $this->user();
        $u2 = $this->user();
        [$p1] = $this->payment($u1);
        [$p2] = $this->payment($u2, 'PENDING');
        [$pa] = $this->payment($admin);
        $r = AdminUsers::purgeAll('ELIMINAR TODO', 'mi-clave', 'limpiar pruebas', $admin);
        $this->assertGreaterThanOrEqual(2, $r['users']);
        $this->assertFalse($this->exists('users', $u1));
        $this->assertFalse($this->exists('users', $u2));
        $this->assertTrue($this->exists('users', $admin), 'el administrador se conserva');
        $this->assertTrue($this->exists('users', $other), 'otros administradores también');
        foreach ([$p1, $p2, $pa] as $p) {
            $this->assertFalse($this->exists('payments', $p), 'todas las compras se eliminan');
        }
        $this->assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM admin_audit WHERE action = 'purge.all' AND user_id = $admin")->fetchColumn());
    }

    public function testPurgeConCuentaSoloGoogleSeConfirmaConElCorreo(): void
    {
        $admin = $this->user(true, 'aleatoria', false);
        $email = (string) self::$pdo->query("SELECT email FROM users WHERE id = $admin")->fetchColumn();
        try {
            AdminUsers::purgeAll('ELIMINAR TODO', 'otro@t.test', 'x', $admin);
            $this->fail('debió rechazar el correo');
        } catch (InvalidArgumentException) {
        }
        $r = AdminUsers::purgeAll('ELIMINAR TODO', strtoupper($email), 'x', $admin);
        $this->assertIsInt($r['users']);
    }
}
