<?php
declare(strict_types=1);

/**
 * Verificación de correo al crear la cuenta: código de 6 dígitos (guardado solo como hash con sal), caduca en
 * 15 minutos, 5 intentos y reenvío no antes de 60 s. Solo se EXIGE si el SMTP está configurado (Mailer::configured()),
 * para no dejar sin acceso a nadie donde no hay correo. Las cuentas con Google llegan ya verificadas.
 */
final class EmailVerification
{
    public const TTL_SECS      = 900;
    public const MAX_ATTEMPTS  = 5;
    public const RESEND_SECS   = 60;

    public static function required(): bool
    {
        return Mailer::configured();
    }

    /** @param array<string,mixed> $user Fila con email_verified_at */
    public static function isVerified(array $user): bool
    {
        return !self::required() || !empty($user['email_verified_at']);
    }

    /** @return array{ok:bool, error:?string} ok=true también si la cuenta ya estaba verificada. */
    public static function issue(int $userId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT email, email_verified_at FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$userId]);
            $u = $st->fetch();
            if (!$u) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Cuenta no encontrada.'];
            }
            if ($u['email_verified_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => true, 'error' => null];
            }
            $st = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, last_sent_at, UTC_TIMESTAMP()) FROM email_verifications WHERE user_id = ?');
            $st->execute([$userId]);
            $since = $st->fetchColumn();
            if ($since !== false && (int) $since < self::RESEND_SECS) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Espera ' . (self::RESEND_SECS - (int) $since) . ' s antes de pedir otro código.'];
            }
            $code = sprintf('%06d', random_int(0, 999999));
            $salt = bin2hex(random_bytes(8));
            $pdo->prepare('INSERT INTO email_verifications (user_id, code_hash, salt, attempts, expires_at, last_sent_at)
                           VALUES (?, ?, ?, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())
                           ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), salt = VALUES(salt), attempts = 0,
                                                   expires_at = VALUES(expires_at), last_sent_at = VALUES(last_sent_at)')
                ->execute([$userId, self::hash($salt, $code), $salt, self::TTL_SECS]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Fuera de la transacción: una conexión SMTP lenta no debe mantener bloqueada la fila del usuario.
        $sent = Mailer::send(
            (string) $u['email'],
            'Tu código de verificación de LovePages',
            "Tu código de verificación es: $code\n\nCaduca en " . intdiv(self::TTL_SECS, 60) . " minutos. Si no creaste una cuenta en LovePages, ignora este mensaje.\n\n"
            . 'Soporte: ' . Mailer::supportEmail() . "\n"
        );
        if (!$sent) {
            $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);   // permite reintentar ya
            return ['ok' => false, 'error' => 'No pudimos enviar el correo. Inténtalo de nuevo en un momento.'];
        }
        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool, error:?string} */
    public static function verify(int $userId, string $code): array
    {
        $code = trim($code);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT code_hash, salt, attempts, (expires_at < UTC_TIMESTAMP()) AS expired FROM email_verifications WHERE user_id = ? FOR UPDATE');
            $st->execute([$userId]);
            $row = $st->fetch();
            if (!$row) {
                $res = ['ok' => false, 'error' => 'Pide un código nuevo.'];
            } elseif ((int) $row['expired'] === 1 || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
                $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
                $res = ['ok' => false, 'error' => 'El código caducó o se agotaron los intentos. Pide uno nuevo.'];
            } elseif (preg_match('/^\d{6}$/', $code) === 1 && hash_equals((string) $row['code_hash'], self::hash((string) $row['salt'], $code))) {
                $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL')->execute([$userId]);
                $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
                $res = ['ok' => true, 'error' => null];
            } else {
                $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE user_id = ?')->execute([$userId]);
                $left = self::MAX_ATTEMPTS - (int) $row['attempts'] - 1;
                $res = ['ok' => false, 'error' => 'Código incorrecto.' . ($left > 0 ? " Te quedan $left intentos." : ' Pide un código nuevo.')];
            }
            $pdo->commit();   // también en los fallos: el contador de intentos debe persistir
            return $res;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function hash(string $salt, string $code): string
    {
        return hash('sha256', $salt . ':' . $code);
    }
}
