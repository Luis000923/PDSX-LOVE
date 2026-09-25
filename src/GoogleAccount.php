<?php
declare(strict_types=1);

/**
 * Cuentas de LovePages con Google: alta, vinculación por correo y último acceso.
 *
 * Reglas de seguridad que se aplican aquí (no en los scripts de entrada):
 *   - El correo debe venir VERIFICADO por Google. Es lo que permite vincular sin preguntar nada:
 *     quien controla ese buzón ES esa persona. Sin `email_verified`, cualquiera podría
 *     vincularse la cuenta de otro registrant un `sub` de Google.
 *   - El vínculo es `google_id` (el `sub` de Google), no el correo: si el usuario cambia su
 *     correo en Google, la cuenta sigue siendo la misma.
 *   - Una cuenta suspendida nunca entra por OAuth, igual que por contraseña.
 */
final class GoogleAccount
{
    /** Hosts de Google desde los que se acepta una foto de perfil (ver ImageStore/CSP). */
    public const AVATAR_HOSTS = [
        'lh3.googleusercontent.com',
        'lh4.googleusercontent.com',
        'lh5.googleusercontent.com',
        'lh6.googleusercontent.com',
    ];

    /**
     * ¿Hay que enviar a esta cuenta a /auth/google_alias.php?
     *
     * True cuando la cuenta entra por Google, aún no tiene alias y nunca lo ha rechazado. Cubre tanto
     * el alta nueva como las cuentas que ya existían cuando se añadió la pantalla (por eso no basta
     * con mirar el `created` de signIn()): así el alias se pide una vez, y solo a quien puede elegirlo.
     *
     * Una cuenta creada con contraseña tiene `google_id` NULL, así que nunca entra aquí: en
     * register.php el alias ya se pidió.
     */
    public static function needsAliasPrompt(int $userId): bool
    {
        $st = db()->prepare('SELECT google_id, display_name, alias_dismissed_at FROM users WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if ($row === false) {
            return false;
        }
        return is_string($row['google_id'] ?? null)
            && $row['google_id'] !== ''
            && ($row['display_name'] === null || $row['display_name'] === '')
            && $row['alias_dismissed_at'] === null;
    }

    /**
     * Marca que el usuario rechazó el alias, para no volvérselo a preguntar en cada acceso con Google.
     * Guardar un alias después (desde el perfil) deja la marca sin efecto: ya no hay nada que preguntar.
     */
    public static function dismissAliasPrompt(int $userId): void
    {
        db()->prepare('UPDATE users SET alias_dismissed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
    }

    /**
     * Inicia sesión con Google: reutiliza la cuenta existente, vincula una creada con contraseña
     * al mismo correo o da de alta una nueva (plan gratuito, saldo 0, código de referido).
     *
     * @param  string $refCode código de referido de quien trajo al usuario (opcional)
     * @return array{id:int, created:bool}
     * @throws GoogleAuthException
     */
    public static function signIn(
        string $sub,
        string $email,
        bool $emailVerified,
        ?string $avatar = null,
        string $refCode = '',
    ): array {
        $sub = trim($sub);
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sub) !== 1) {
            throw new GoogleAuthException('Identificador de Google con formato inesperado.');
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new GoogleAuthException('Google devolvió un correo con formato inválido.');
        }
        if (!$emailVerified) {
            throw new GoogleAuthException(
                'Google no marque el correo como verificado; no se crea ni vincula la cuenta.',
                'Google no ha verificado este correo, así que no podemos iniciar tu sesión. Prueba con tu correo y contraseña.'
            );
        }

        $linked = self::findByGoogleId($sub);
        if ($linked !== null) {
            return self::existing((int) $linked['id'], $linked);
        }

        // Mismo correo, cuenta creada antes con contraseña: se vincula (el buzón está verificado).
        $byEmail = self::findByEmail($email);
        if ($byEmail !== null) {
            self::assertUsable($byEmail);
            self::assertLinkable($byEmail);
            self::link((int) $byEmail['id'], $sub, $avatar);
            return self::existing((int) $byEmail['id'], $byEmail);
        }

        try {
            return ['id' => self::insert($email, $sub, $avatar, Referrals::normalize($refCode)), 'created' => true];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;   // cualquier otro fallo de BD es del servidor, no de este usuario
            }
            // Carrera con otra petición idéntica (mismo correo o mismo `sub`): la cuenta ya existe.
            $row = self::findByGoogleId($sub) ?? self::findByEmail($email);
            if ($row === null) {
                throw new GoogleAuthException('No se pudo crear la cuenta de Google: ' . $e->getMessage());
            }
            if (!self::hasGoogleId($row, $sub)) {
                self::assertUsable($row);
                self::assertLinkable($row);
                self::link((int) $row['id'], $sub, $avatar);
            }
            return self::existing((int) $row['id'], $row);
        }
    }

    /**
     * Foto de perfil: solo HTTPS y solo los hosts de Google. Cualquier otra URL (o vacía) → null,
     * porque la app solo admite imágenes propias y de esos hosts concretos.
     */
    public static function safeAvatar(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 512) {
            return null;
        }
        $parts = parse_url($raw);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, self::AVATAR_HOSTS, true) ? $raw : null;
    }

    /**
     * Fila del usuario por `google_id` (el `sub` de Google).
     *
     * @return array<string,mixed>|null
     */
    public static function findByGoogleId(string $sub): ?array
    {
        $st = db()->prepare('SELECT id, email, google_id, is_suspended FROM users WHERE google_id = ?');
        $st->execute([$sub]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fila del usuario por correo (colación insensible a mayúsculas y tildes: el índice es el que manda).
     *
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $st = db()->prepare('SELECT id, email, google_id, is_suspended, is_admin FROM users WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Acceso de una cuenta ya existente: comprueba que siga viva y anota el último acceso.
     *
     * @param  array<string,mixed> $row
     * @return array{id:int, created:bool}
     * @throws GoogleAuthException
     */
    private static function existing(int $userId, array $row): array
    {
        self::assertUsable($row);
        self::touch($userId);
        return ['id' => $userId, 'created' => false];
    }

    /**
     * @param array<string,mixed> $row
     * @throws GoogleAuthException
     */
    private static function assertUsable(array $row): void
    {
        if ((int) ($row['is_suspended'] ?? 0) === 1) {
            // Sin revelar el motivo de la suspensión, igual que en login.php.
            throw new GoogleAuthException('Cuenta suspendida (' . (int) $row['id'] . ').', 'Tu cuenta está suspendida. Contacta a soporte.');
        }
    }

    /**
     * Una cuenta de administrador nunca se vincula a Google por coincidencia de correo: quien consiga
     * un Google con ese correo (p. ej. un alias/dominio reasignado) no puede heredar sus privilegios.
     * El admin entra con su contraseña, o con el Google que ya tenga vinculado (rama findByGoogleId).
     *
     * @param array<string,mixed> $row
     * @throws GoogleAuthException
     */
    private static function assertLinkable(array $row): void
    {
        if ((int) ($row['is_admin'] ?? 0) === 1) {
            throw new GoogleAuthException(
                'Vinculación por correo rechazada: la cuenta ' . (int) $row['id'] . ' es administradora.',
                'Esta cuenta solo puede entrar con su correo y contraseña.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function hasGoogleId(array $row, string $sub): bool
    {
        return is_string($row['google_id'] ?? null) && $row['google_id'] !== '' && hash_equals($row['google_id'], $sub);
    }

    /** Marca el último acceso (UTC, como toda la app). */
    private static function touch(int $userId): void
    {
        db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
    }

    /** Vincula la cuenta de Google a una cuenta ya creada con contraseña. */
    private static function link(int $userId, string $sub, ?string $avatar): void
    {
        db()->prepare('UPDATE users SET google_id = ?, avatar_url = COALESCE(?, avatar_url), email_verified_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$sub, self::safeAvatar($avatar), $userId]);
    }

    /**
     * Alta de una cuenta nueva: plan gratuito (membership_tier_id NULL), saldo 0, correo verificado
     * y código de referido propio. La contraseña es aleatoria e inútil: la cuenta entra por Google
     * (has_password = 0), pero la columna sigue siendo NOT NULL.
     */
    private static function insert(string $email, string $sub, ?string $avatar, string $refCode): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO users (email, password_hash, has_password, google_id, avatar_url, email_verified_at, last_login_at, is_admin)
                 VALUES (?, ?, 0, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)'
            );
            $st->execute([$email, self::unusablePassword(), $sub, self::safeAvatar($avatar)]);
            $id = (int) $pdo->lastInsertId();
            Referrals::codeFor($id);
            if ($refCode !== '') {
                Referrals::attach($pdo, $id, $refCode);   // código desconocido: se ignora sin avisar
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Hash de una contraseña aleatoria de 32 bytes: nadie la conoce, así que nadie entra por ella. */
    private static function unusablePassword(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);   // 64 bytes < 72 de bcrypt
    }
}
