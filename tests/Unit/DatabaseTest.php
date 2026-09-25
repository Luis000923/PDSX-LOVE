<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integración contra MySQL real (la BD de tests/bootstrap.php, siempre `*_test`):
 * esquema, migración idempotente y las consultas que antes usaban sintaxis SQLite.
 * Si no hay servidor MySQL alcanzable, las pruebas se omiten (en CI el servicio siempre existe).
 */
final class DatabaseTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        try {
            // Base limpia: se conecta sin migrar (se replica db() sin llamar a db_migrate).
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

        self::$pdo = db();   // conexión compartida (puede venir ya cacheada de otra prueba)
        db_migrate(self::$pdo);   // recrea el esquema y anota la versión sobre la base recién vaciada
    }

    public function testMigrationStampsSchemaVersion(): void
    {
        self::assertSame(DB_SCHEMA_VERSION, db_schema_version(self::$pdo));
    }

    public function testMigrationV4UpgradesAV3Schema(): void
    {
        $pdo = self::$pdo;
        // Devuelve la base a la forma de la v3: price_cop en vez de price_usd, payments sin template_id.
        $pdo->exec('ALTER TABLE payments DROP FOREIGN KEY fk_payments_template');
        $pdo->exec('ALTER TABLE payments DROP COLUMN template_id');
        $pdo->exec('ALTER TABLE templates DROP CHECK chk_templates_price_usd');
        $pdo->exec('ALTER TABLE templates DROP COLUMN price_usd');
        $pdo->exec('ALTER TABLE templates ADD COLUMN price_cop INT NOT NULL DEFAULT 0');
        // ...y a la de v4 en lo de niveles: sin membership_tier_id/tier_id/kind/category, con un Premium previo.
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_tier');
        $pdo->exec('ALTER TABLE users DROP COLUMN membership_tier_id');
        $pdo->exec('ALTER TABLE payments DROP FOREIGN KEY fk_payments_tier');
        $pdo->exec('ALTER TABLE payments DROP COLUMN tier_id');
        $pdo->exec('ALTER TABLE templates DROP COLUMN kind, DROP COLUMN category');
        $pdo->exec("INSERT INTO users (email, password_hash, is_premium) VALUES ('legacy@example.com', 'x', 1)");
        $pdo->exec('DELETE FROM schema_version');
        $pdo->exec('INSERT INTO schema_version (version) VALUES (3)');

        db_migrate($pdo);

        self::assertSame(DB_SCHEMA_VERSION, db_schema_version($pdo));
        $pdo->exec('DELETE FROM schema_version WHERE version < ' . DB_SCHEMA_VERSION);
        self::assertTrue(db_column_exists($pdo, 'templates', 'price_usd'));
        self::assertFalse(db_column_exists($pdo, 'templates', 'price_cop'));
        self::assertTrue(db_column_exists($pdo, 'payments', 'template_id'));
        foreach ([['users', 'membership_tier_id'], ['payments', 'tier_id'], ['templates', 'kind'], ['templates', 'category']] as [$t, $c]) {
            self::assertTrue(db_column_exists($pdo, $t, $c), "$t.$c");
        }
        self::assertSame('eterno', $pdo->query("SELECT mt.slug FROM users u JOIN membership_tiers mt ON mt.id = u.membership_tier_id WHERE u.email = 'legacy@example.com'")->fetchColumn(), 'los Premium previos pasan a Eterno');
        $pdo->exec("DELETE FROM users WHERE email = 'legacy@example.com'");
    }

    public function testMigrationIsIdempotent(): void
    {
        db_migrate(self::$pdo);   // segunda pasada sobre una base ya migrada: no debe fallar ni duplicar
        db_migrate(self::$pdo);
        $rows = (int) self::$pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
        self::assertSame(1, $rows);
        self::assertSame(3, (int) self::$pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn(), 'INSERT IGNORE no duplica las plantillas base (2 visibles + la oculta html-propio)');
        self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM templates WHERE slug = 'html-propio' AND kind = 'user'")->fetchColumn());
    }

    public function testMigrationReleasesItsLock(): void
    {
        self::assertSame(1, (int) self::$pdo->query("SELECT IS_FREE_LOCK('" . DB_MIGRATION_LOCK . "')")->fetchColumn());
    }

    public function testConnectionUsesStrictNativePreparesAndUtc(): void
    {
        self::assertFalse((bool) self::$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertSame(PDO::ERRMODE_EXCEPTION, self::$pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame('+00:00', self::$pdo->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testAllTablesAreInnoDbUtf8mb4(): void
    {
        $bad = self::$pdo->query(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND (engine <> 'InnoDB' OR table_collation <> 'utf8mb4_unicode_ci')"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $bad);
    }

    public function testEmailAndPromoCodeAreCaseInsensitiveUnique(): void
    {
        self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['Ana@Example.com', 'x']);
        $st = self::$pdo->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute(['ana@example.com']);
        self::assertNotFalse($st->fetch(), 'la búsqueda ignora mayúsculas (antes COLLATE NOCASE)');

        try {
            self::$pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['ANA@EXAMPLE.COM', 'y']);
            self::fail('el correo duplicado debía violar UNIQUE');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode(), 'el código que esperan register.php/promos.php');
        }
    }

    public function testBooleanCheckConstraintRejectsOutOfRange(): void
    {
        $this->expectException(PDOException::class);
        self::$pdo->prepare('INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)')->execute(['bad@example.com', 'x', 2]);
    }

    public function testSettingUpsertInsertsThenUpdates(): void
    {
        Admin::setSetting('announcement_text', 'uno');
        Admin::setSetting('announcement_text', 'dos');
        Admin::setSetting('clave_desconocida', 'ignorada');
        $rows = self::$pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('announcement_text','clave_desconocida')")->fetchAll();
        self::assertSame([['key' => 'announcement_text', 'value' => 'dos']], $rows);
    }

    public function testFindUsablePromoHonoursExpiryUsesAndActive(): void
    {
        $ins = self::$pdo->prepare('INSERT INTO promos (code, discount_percent, is_active, expires_at, max_uses, uses) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute(['VIGENTE', 10, 1, gmdate('Y-m-d', strtotime('+1 day')), 0, 0]);
        $ins->execute(['HOY', 10, 1, gmdate('Y-m-d'), 0, 0]);
        $ins->execute(['CADUCADO', 10, 1, gmdate('Y-m-d', strtotime('-1 day')), 0, 0]);
        $ins->execute(['SINFECHA', 10, 1, null, 0, 0]);
        $ins->execute(['AGOTADO', 10, 1, null, 2, 2]);
        $ins->execute(['APAGADO', 10, 0, null, 0, 0]);

        self::assertNotNull(Admin::findUsablePromo('vigente'), 'insensible a mayúsculas');
        self::assertNotNull(Admin::findUsablePromo('HOY'), 'el último día sigue valiendo');
        self::assertNotNull(Admin::findUsablePromo('SINFECHA'));
        self::assertNull(Admin::findUsablePromo('CADUCADO'));
        self::assertNull(Admin::findUsablePromo('AGOTADO'));
        self::assertNull(Admin::findUsablePromo('APAGADO'));
    }

    public function testCascadeAndRestrictForeignKeys(): void
    {
        $pdo = self::$pdo;
        $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['fk@example.com', 'x']);
        $uid = (int) $pdo->lastInsertId();
        $tpl = (int) $pdo->query("SELECT id FROM templates WHERE slug = 'free-minimal'")->fetchColumn();

        $pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data) VALUES (?, ?, ?, ?)')->execute([$uid, $tpl, 'abcd2345', '{}']);
        $pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents) VALUES (?, ?, ?)')->execute([$uid, 'LP-fk-1', 499]);
        $pdo->prepare('INSERT INTO admin_audit (user_id, action) VALUES (?, ?)')->execute([$uid, 'test']);

        try {
            $pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$tpl]);
            self::fail('una plantilla con páginas no se puede borrar (RESTRICT)');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM user_sites WHERE slug = 'abcd2345'")->fetchColumn(), 'CASCADE: páginas');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE reference = 'LP-fk-1'")->fetchColumn(), 'CASCADE: pagos');
        self::assertNull($pdo->query("SELECT user_id FROM admin_audit WHERE action = 'test'")->fetchColumn(), 'SET NULL: la auditoría se conserva');
    }

    public function testRecentPendingPaymentsWindowQuery(): void
    {
        $pdo = self::$pdo;
        $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['pay@example.com', 'x']);
        $uid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents, created_at) VALUES (?, ?, 499, ?)');
        $ins->execute([$uid, 'LP-new', gmdate('Y-m-d H:i:s', time() - 600)]);      // hace 10 min: cuenta
        $ins->execute([$uid, 'LP-old', gmdate('Y-m-d H:i:s', time() - 7200)]);     // hace 2 h: no cuenta

        // Misma consulta que public/checkout_wompi.php
        $st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'PENDING' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
        $st->execute([$uid]);
        self::assertSame(1, (int) $st->fetchColumn());
    }

    public function testWebhookIdempotentUpdateAffectsOneRowOnce(): void
    {
        $pdo = self::$pdo;
        $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['wh@example.com', 'x']);
        $uid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payments (user_id, reference, amount_in_cents) VALUES (?, ?, 499)')->execute([$uid, 'LP-wh']);

        // Misma sentencia que public/webhook_wompi.php: rowCount() decide si se disparan los efectos.
        $up = $pdo->prepare("UPDATE payments SET status = 'APPROVED', wompi_transaction_id = ?, updated_at = UTC_TIMESTAMP() WHERE reference = ? AND status <> 'APPROVED'");
        $up->execute(['tx1', 'LP-wh']);
        self::assertSame(1, $up->rowCount());
        $up->execute(['tx1', 'LP-wh']);
        self::assertSame(0, $up->rowCount(), 'un reintento del webhook no repite efectos');
    }

    public function testNativeIntegersComeBackAsInts(): void
    {
        $row = self::$pdo->query("SELECT is_premium, is_active, price_usd FROM templates WHERE slug = 'premium-heart'")->fetch();
        self::assertSame(['is_premium' => 1, 'is_active' => 1, 'price_usd' => '0.00'], $row);
    }

    public function testSqlSplitterHandlesCommentsAndTrailingSemicolons(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            db_split_sql("-- cabecera; con punto y coma\nSELECT 1; -- final\nSELECT 2;\n")
        );
    }
}
