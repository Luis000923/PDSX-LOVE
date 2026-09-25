<?php
declare(strict_types=1);

/** Operaciones de cuenta del perfil. Todo con sentencias preparadas; sin salida HTML. */
final class Profile
{
    /**
     * Cambia el correo. Devuelve un mensaje de error, o null si se guardó.
     * La unicidad la impone el índice UNIQUE (sin carreras); el mensaje de duplicado es explícito
     * porque el usuario ya está autenticado y no hay enumeración que proteger.
     */
    public static function changeEmail(int $userId, string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return 'Correo inválido.';
        }
        try {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'Ese correo ya está en uso.';
            }
            throw $e;
        }
        return null;
    }

    /** Cambia la contraseña verificando la actual. Devuelve un mensaje de error, o null si se guardó. */
    public static function changePassword(int $userId, string $current, string $new, string $confirm): ?string
    {
        if (strlen($new) < 8 || strlen($new) > 72) {   // bcrypt trunca a 72 bytes
            return 'La contraseña nueva debe tener entre 8 y 72 caracteres.';
        }
        if (!hash_equals($new, $confirm)) {
            return 'La confirmación no coincide.';
        }
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            return 'La contraseña actual no es correcta.';
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        return null;
    }

    /** @return list<array<string,mixed>> pagos del usuario, recientes primero */
    public static function payments(int $userId): array
    {
        $st = db()->prepare(
            'SELECT p.amount_in_cents, p.currency, p.status, p.created_at, t.name AS template_name, mt.name AS tier_name
               FROM payments p LEFT JOIN templates t ON t.id = p.template_id
               LEFT JOIN membership_tiers mt ON mt.id = p.tier_id
              WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 50'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }
}
