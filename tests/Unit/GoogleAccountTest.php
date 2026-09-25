<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integración contra MySQL real (la BD desechable de tests/bootstrap.php, siempre `*_test`):
 * migración v21 y todo el camino de una cuenta de Google —alta, vinculación por correo,
 * último acceso y cuenta suspendida— con las mismas consultas que usa el callback.
 * Si no hay MySQL alcanzable, se omiten (en CI el servicio siempre está).
 */
final class GoogleAccountTest extends TestCase
{
    private const SUB = '118292290384729837421';

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

        self::$pdo = db();     // conexión compartida
        db_migrate(self::$pdo); // crea el esquema actual, migraciones v4..v21 incluidas
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

    private static function insertPasswordUser(string $email): int
    {
        $st = self::$pdo->prepare('INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)');
        $st->execute([$email, password_hash('clave-larga-123', PASSWORD_DEFAULT), 'Alias' . substr(md5($email), 0, 6)]);
        return (int) self::$pdo->lastInsertId();
    }

    // ------------------------------------------------------------- migración ---

    public function testMigrationV21AddsTheGoogleColumnsAndIsIdempotent(): void
    {
        self::assertSame(DB_SCHEMA_VERSION, db_schema_version(self::$pdo));
        foreach (['google_id', 'avatar_url', 'email_verified_at', 'last_login_at', 'has_password'] as $col) {
            self::assertTrue(db_column_exists(self::$pdo, 'users', $col), "users.$col");
        }
        $idx = self::$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                                     AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_id'");
        $idx->execute();
        self::assertSame(1, (int) $idx->fetchColumn(), 'uq_users_google_id');

        db_migrate(self::$pdo);   // segunda pasada sobre un esquema ya migrado: no debe fallar
        self::assertSame(DB_SCHEMA_VERSION, db_schema_version(self::$pdo));
    }

    // ------------------------------------------------------------------ alta ---

    public function testSignInCreatesAReadyToUseAccount(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true, 'https://lh3.googleusercontent.com/a/x.jpg');
        self::assertTrue($r['created']);

        $u = self::user($r['id']);
        self::assertSame('nueva@example.com', $u['email']);
        self::assertSame(self::SUB, $u['google_id']);
        self::assertSame('https://lh3.googleusercontent.com/a/x.jpg', $u['avatar_url']);
        self::assertNotNull($u['email_verified_at'], 'el correo viene verificado por Google');
        self::assertNotNull($u['last_login_at']);
        self::assertNotNull($u['created_at']);
        self::assertSame(0, (int) $u['coins'], 'plan y saldo los fija el servidor, no Google');
        self::assertNull($u['membership_tier_id'], 'empieza en el plan gratuito');
        self::assertSame(0, (int) $u['is_premium']);
        self::assertSame(0, (int) $u['has_password'], 'no tiene contraseña propia');

        self::assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', (string) $u['referral_code'], 'referido propio asignado');
    }

    public function testNewAccountIsNeverAdmin(): void
    {
        $first = GoogleAccount::signIn('100000000000000000001', 'primero@example.com', true);
        self::assertSame(0, (int) self::user($first['id'])['is_admin'], 'ni el primer usuario es admin automático');
    }

    public function testAdminAccountIsNotLinkedByEmailMatch(): void
    {
        $admin = self::insertPasswordUser('admin@example.com');
        self::$pdo->exec('UPDATE users SET is_admin = 1 WHERE id = ' . $admin);

        try {
            GoogleAccount::signIn(self::SUB, 'admin@example.com', true);
            self::fail('debió rechazar la vinculación');
        } catch (GoogleAuthException) {
            // esperado
        }
        $u = self::user($admin);
        self::assertNull($u['google_id']);
        self::assertSame(1, (int) $u['is_admin']);
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no se crea duplicado');
    }

    public function testAdminAlreadyLinkedToGoogleStillSignsIn(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'admin@example.com', true);
        self::$pdo->exec('UPDATE users SET is_admin = 1 WHERE id = ' . $r['id']);
        $again = GoogleAccount::signIn(self::SUB, 'admin@example.com', true);
        self::assertSame($r['id'], $again['id']);
    }

    public function testGeneratedPasswordCannotLogIn(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        $hash = (string) self::user($r['id'])['password_hash'];
        self::assertNotSame('', $hash, 'la columna es NOT NULL: se guarda un hash inútil');
        self::assertFalse(password_verify('', $hash));
        self::assertFalse(password_verify('nueva@example.com', $hash));
        self::assertFalse(password_verify('contraseña-aleatoria', $hash));
    }

    public function testReferralCodeIsAttachedOnFirstSignIn(): void
    {
        $inviter = self::insertPasswordUser('quien.invita@example.com');
        $code = (string) Referrals::codeFor($inviter);

        $r = GoogleAccount::signIn('100000000000000000009', 'nuevo@example.com', true, null, $code);
        self::assertSame($inviter, (int) self::user($r['id'])['referred_by']);
    }

    public function testUnknownReferralCodeIsIgnored(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nuevo@example.com', true, null, 'BASURA99');
        self::assertNull(self::user($r['id'])['referred_by']);
    }

    // ------------------------------------------------------- reutilización ---

    public function testSecondSignInReusesTheAccountAndUpdatesLastLogin(): void
    {
        $first = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        self::$pdo->exec('UPDATE users SET last_login_at = NULL WHERE id = ' . $first['id']);

        $second = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertNotNull(self::user($first['id'])['last_login_at'], 'el último acceso se anota siempre');
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no se duplica la cuenta');
    }

    public function testSignInByGoogleIdIgnoresTheEmailGoogleNowReports(): void
    {
        $first = GoogleAccount::signIn(self::SUB, 'viejo@example.com', true);
        $again = GoogleAccount::signIn(self::SUB, 'nuevo@example.com', true);   // el usuario cambió su correo en Google
        self::assertSame($first['id'], $again['id'], 'misma cuenta de Google, aunque el correo haya cambiado');
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    // ---------------------------------------------------------- vinculación ---

    public function testFirstGoogleSignInLinksAnAccountCreatedWithPassword(): void
    {
        $id = self::insertPasswordUser('ya.registrado@example.com');
        $r = GoogleAccount::signIn('100000000000000000003', 'Ya.Registrado@example.com', true);

        self::assertFalse($r['created'], 'la cuenta ya existía: no se crea otra');
        self::assertSame($id, $r['id']);
        $u = self::user($id);
        self::assertSame('100000000000000000003', $u['google_id'], 'se vincula por correo verificado');
        self::assertNotNull($u['email_verified_at']);
        self::assertSame(1, (int) $u['has_password'], 'sigue entrando con su contraseña');
    }

    public function testOneGoogleAccountCannotBeLinkedTwice(): void
    {
        $a = self::insertPasswordUser('a@example.com');
        GoogleAccount::signIn('100000000000000000004', 'a@example.com', true);
        $b = self::insertPasswordUser('b@example.com');
        GoogleAccount::signIn('100000000000000000004', 'b@example.com', true);
        self::assertSame('100000000000000000004', (string) self::user($a)['google_id']);
        self::assertNull(self::user($b)['google_id'], 'el segundo vínculo se resuelve por el google_id, no por correo');
    }

    // -------------------------------------------------------------- rechazos ---

    public function testUnverifiedEmailIsRefused(): void
    {
        try {
            GoogleAccount::signIn(self::SUB, 'luna@example.com', false);
            self::fail('un correo sin verificar no debe crear la cuenta');
        } catch (GoogleAuthException $e) {
            self::assertStringContainsString('verificado', $e->getMessage());
        }
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testSuspensionBlocksGoogleToo(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'suspendido@example.com', true);
        self::$pdo->exec('UPDATE users SET is_suspended = 1 WHERE id = ' . $r['id']);

        $this->expectException(GoogleAuthException::class);
        $this->expectExceptionMessage('suspendida');
        GoogleAccount::signIn(self::SUB, 'suspendido@example.com', true);
    }

    public function testSuspendedPasswordAccountIsNotLinkable(): void
    {
        $id = self::insertPasswordUser('bloqueada@example.com');
        self::$pdo->exec('UPDATE users SET is_suspended = 1 WHERE id = ' . $id);

        $this->expectException(GoogleAuthException::class);
        GoogleAccount::signIn('100000000000000000005', 'bloqueada@example.com', true);
    }

    #[DataProvider('badIdentities')]
    public function testMalformedIdentityIsRefused(string $sub, string $email): void
    {
        $this->expectException(GoogleAuthException::class);
        GoogleAccount::signIn($sub, $email, true);
    }

    public static function badIdentities(): array
    {
        return [
            'sub vacío'        => ['', 'luna@example.com'],
            'sub con espacios' => ['12 34', 'luna@example.com'],
            'sub con sql'       => ["12'; DROP TABLE users;--", 'luna@example.com'],
            'correo vacío'     => [self::SUB, ''],
            'correo inválido'   => [self::SUB, 'no-es-un-correo'],
        ];
    }

    // ---------------------------------------------------------------- avatar ---

    #[DataProvider('avatars')]
    public function testOnlyGoogleHttpsAvatarsAreAccepted(?string $in, ?string $expected): void
    {
        self::assertSame($expected, GoogleAccount::safeAvatar($in));
    }

    public static function avatars(): array
    {
        $ok = 'https://lh3.googleusercontent.com/a/ACg8oc-x.jpg';
        return [
            'host de Google'   => [$ok, $ok],
            'lh4'              => ['https://lh4.googleusercontent.com/a/x', 'https://lh4.googleusercontent.com/a/x'],
            'http'             => ['http://lh3.googleusercontent.com/a/x', null],
            'otro host'        => ['https://ejemplo.com/foto.jpg', null],
            'javascript'       => ['javascript:alert(1)', null],
            'vacío'            => ['', null],
            'nulo'             => [null, null],
            'demasiado largo'  => ['https://lh3.googleusercontent.com/' . str_repeat('a', 600), null],
        ];
    }

    public function testAvatarOutsideGoogleIsNotStored(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true, 'https://ejemplo.com/foto.jpg');
        self::assertNull(self::user($r['id'])['avatar_url']);
    }

    // ----------------------------------------------------------------- alias ---
    //
    // Google no pide alias: el alta lo deja en NULL a propósito y /auth/google_alias.php lo recoge
    // después de iniciar sesión, cuando ya se sabe quién es. Estos tests fijan ese contrato: si
    // alguien empieza a adivinar un alias en el INSERT, la pantalla intermedia dejaría de tener
    // sentido y ocuparía el UNIQUE sin que nadie lo haya pedido.

    public function testNewGoogleAccountHasNoAliasSoTheInterstitialHasSomethingToAsk(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        $u = self::user($r['id']);
        self::assertNull($u['display_name'], 'el alta no inventa un alias: se pregunta después');
        self::assertSame(0, (int) $u['show_in_rankings'], 'no se opta por el ranking sin haberlo pedido');
    }

    public function testFirstAliasFromTheInterstitialIsFreeAndOptsIntoRankings(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        self::$pdo->exec('UPDATE users SET coins = 50 WHERE id = ' . $r['id']);

        $saved = Ranking::saveOptIn($r['id'], true, 'Luna_Sv');

        self::assertTrue($saved['ok'], (string) $saved['error']);
        self::assertSame(0, $saved['charged'], 'el primer alias nunca cuesta monedas');
        $u = self::user($r['id']);
        self::assertSame('Luna_Sv', $u['display_name']);
        self::assertSame(1, (int) $u['show_in_rankings']);
        self::assertSame(50, (int) $u['coins'], 'el saldo no se toca');
    }

    public function testInterstitialRefusesAnAliasAnotherUserAlreadyHas(): void
    {
        $other = self::insertPasswordUser('primero@example.com');
        $r     = GoogleAccount::signIn(self::SUB, 'segundo@example.com', true);

        $saved = Ranking::saveOptIn($r['id'], true, (string) self::user($other)['display_name']);

        self::assertFalse($saved['ok']);
        self::assertStringContainsString('en uso', (string) $saved['error']);
        self::assertNull(self::user($r['id'])['display_name'], 'no se guarda un alias rechazado');
    }

    // ------------------------------------------------- a quién se pregunta el alias ---

    public function testANewGoogleAccountIsAskedForItsAlias(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        self::assertTrue(GoogleAccount::needsAliasPrompt($r['id']));
    }

    public function testAReturningGoogleAccountWithoutAnAliasIsAlsoAsked(): void
    {
        // El caso que motivó needsAliasPrompt(): una cuenta de Google que ya existía y sigue sin alias.
        // Si el callback mirara el `created` de signIn() (false aquí) se iría al panel y nunca preguntaría.
        GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);

        $again = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);

        self::assertFalse($again['created'], 'la cuenta ya existía');
        self::assertTrue(GoogleAccount::needsAliasPrompt($again['id']), 'también se le pregunta a quien ya existía');
    }

    public function testDismissingTheAliasStopsThePromptForGood(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        self::assertTrue(GoogleAccount::needsAliasPrompt($r['id']));

        GoogleAccount::dismissAliasPrompt($r['id']);

        self::assertFalse(GoogleAccount::needsAliasPrompt($r['id']), 'no se martillea en cada login');
        self::assertNull(self::user($r['id'])['display_name'], 'desechar el alias no inventa uno');
    }

    public function testASavedAliasAlsoStopsThePrompt(): void
    {
        $r = GoogleAccount::signIn(self::SUB, 'nueva@example.com', true);
        Ranking::saveOptIn($r['id'], true, 'Luna_Sv');
        self::assertFalse(GoogleAccount::needsAliasPrompt($r['id']));
    }

    public function testAPasswordAccountIsNeverAskedBecauseRegisterAlreadyDid(): void
    {
        $id = self::insertPasswordUser('correo@example.com');
        self::assertFalse(GoogleAccount::needsAliasPrompt($id), 'google_id NULL: el alias ya se pidió en register.php');
    }

    public function testMigrationV23AddsTheDismissalColumnAndIsIdempotent(): void
    {
        self::assertTrue(db_column_exists(self::$pdo, 'users', 'alias_dismissed_at'));

        db_migrate(self::$pdo);   // segunda pasada: no debe fallar

        self::assertSame(DB_SCHEMA_VERSION, db_schema_version(self::$pdo));
    }
}
