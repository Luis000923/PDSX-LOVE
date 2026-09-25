<?php
declare(strict_types=1);

/**
 * Alta de cuentas con correo y contraseña.
 *
 * Reglas de seguridad (aquí y no en register.php, para poder probarlas):
 *   - Si el correo ya existe NO se toca nada: ni contraseña, ni roles, ni alias de la cuenta original.
 *   - `is_admin` jamás se calcula ni se lee del cliente: toda cuenta nueva nace con is_admin = 0.
 *     El administrador se designa solo por CLI (bin/make_admin.php).
 */
final class Registration
{
    public const EMAIL_TAKEN = 'Este correo ya está registrado, por favor inicia sesión.';

    /**
     * @return array{id:int|null, error:string|null}
     * @throws PDOException si falla la BD por algo distinto de un duplicado
     */
    public static function create(string $email, string $password, string $alias, string $refCode = ''): array
    {
        $email = strtolower(trim($email));
        $pdo   = db();

        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetchColumn() !== false) {
            return ['id' => null, 'error' => self::EMAIL_TAKEN];
        }

        $pdo->beginTransaction();
        try {
            // show_in_rankings = 1: el alias se pide de forma explícita y visible en el formulario (con aviso).
            $pdo->prepare('INSERT INTO users (email, password_hash, is_admin, display_name, show_in_rankings) VALUES (?, ?, 0, ?, 1)')
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $alias]);
            $id = (int) $pdo->lastInsertId();
            Referrals::codeFor($id);
            if ($refCode !== '') {
                Referrals::attach($pdo, $id, $refCode);   // código desconocido: se ignora sin avisar
            }
            $pdo->commit();
            return ['id' => $id, 'error' => null];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {   // carrera con otro alta del mismo correo (o alias): el UNIQUE manda
                return ['id' => null, 'error' => self::EMAIL_TAKEN];
            }
            throw $e;
        }
    }
}
