<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integración contra MySQL real (BD `*_test`): registrar un correo ya existente falla y no altera
 * la cuenta original (contraseña, is_admin, alias); las cuentas nuevas nunca nacen admin.
 */
final class RegistrationTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        try {
            $raw = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), (int) getenv('DB_PORT'), getenv('DB_NAME')),
                (string) getenv('DB_USER'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL no disponible: ' . $e->getMessage());
        }
        $raw->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($raw->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $raw->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $raw->exec('SET FOREIGN_KEY_CHECKS = 1');
        unset($raw);

        self::$pdo = db();
        db_migrate(self::$pdo);
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM users');
    }

    /** @return array<string,mixed> */
    private static function user(int $id): array
    {
        $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        self::assertIsArray($row);
        return $row;
    }

    private static function insertAdmin(string $email): int
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash, is_admin, display_name) VALUES (?, ?, 1, ?)')
            ->execute([$email, password_hash('clave-admin-123', PASSWORD_DEFAULT), 'Root']);
        return (int) self::$pdo->lastInsertId();
    }

    public function testFirstEverUserIsNotAdmin(): void
    {
        $r = Registration::create('primero@example.com', 'clave-larga-123', 'Primero');
        self::assertNull($r['error']);
        self::assertSame(0, (int) self::user((int) $r['id'])['is_admin']);
    }

    public function testDuplicateEmailIsRejectedAndOriginalAccountIsUntouched(): void
    {
        $admin  = self::insertAdmin('admin@example.com');
        $before = self::user($admin);

        $r = Registration::create('admin@example.com', 'otra-clave-999', 'Intruso');

        self::assertNull($r['id']);
        self::assertSame(Registration::EMAIL_TAKEN, $r['error']);
        self::assertSame($before, self::user($admin), 'contraseña, rol y alias intactos');
        self::assertTrue(password_verify('clave-admin-123', (string) self::user($admin)['password_hash']));
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'sin duplicados');
    }

    public function testDuplicateEmailIsCaseInsensitive(): void
    {
        $admin = self::insertAdmin('admin@example.com');
        $r = Registration::create('ADMIN@Example.com', 'otra-clave-999', 'Intruso');
        self::assertNull($r['id']);
        self::assertSame(1, (int) self::user($admin)['is_admin']);
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testNewAccountsAlwaysGetStandardPrivileges(): void
    {
        self::insertAdmin('admin@example.com');
        $_POST['is_admin'] = '1';   // un cliente malicioso lo intenta: ni se lee
        try {
            $r = Registration::create('nuevo@example.com', 'clave-larga-123', 'Nuevo');
        } finally {
            unset($_POST['is_admin']);
        }
        self::assertSame(0, (int) self::user((int) $r['id'])['is_admin']);
    }

    public function testAdminGuardChecksTheDatabaseRole(): void
    {
        $admin = self::insertAdmin('admin@example.com');
        $reg   = Registration::create('normal@example.com', 'clave-larga-123', 'Normal');

        self::assertTrue(Admin::isAdminInDb($admin));
        self::assertFalse(Admin::isAdminInDb((int) $reg['id']));
        self::assertFalse(Admin::isAdminInDb(999999));

        self::$pdo->exec('UPDATE users SET is_suspended = 1 WHERE id = ' . $admin);
        self::assertFalse(Admin::isAdminInDb($admin), 'un admin suspendido pierde el acceso');
    }
}
