<?php
declare(strict_types=1);

/**
 * Check-in diario: una vez por día calendario (hora de El Salvador, como el resto de la app) el usuario reclama
 * monedas gratis. Fila del usuario con FOR UPDATE + UPDATE condicional por fecha: dos peticiones simultáneas
 * (o dos pestañas) no pueden cobrar el mismo día dos veces.
 */
final class Checkin
{
    public const DEFAULT_COINS = 1;
    public const MAX_COINS     = 10;
    public const TZ            = 'America/El_Salvador';

    public static function coins(): int
    {
        $raw = trim(Admin::setting('checkin_coins'));
        return $raw !== '' && ctype_digit($raw) && (int) $raw >= 1 ? min(self::MAX_COINS, (int) $raw) : self::DEFAULT_COINS;
    }

    public static function today(?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    public static function canClaim(int $userId, ?DateTimeImmutable $now = null): bool
    {
        $st = db()->prepare('SELECT last_checkin_date FROM users WHERE id = ?');
        $st->execute([$userId]);
        $last = $st->fetchColumn();
        return $last === false ? false : ($last === null || (string) $last < self::today($now));
    }

    /** @return array{ok:bool, coins:int, error:?string} */
    public static function claim(int $userId, ?DateTimeImmutable $now = null): array
    {
        $pdo   = db();
        $today = self::today($now);
        $own   = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT last_checkin_date, is_suspended FROM users WHERE id = ? FOR UPDATE');
            $st->execute([$userId]);
            $u = $st->fetch();
            if (!$u) {
                $res = ['ok' => false, 'coins' => 0, 'error' => 'Cuenta no encontrada.'];
            } elseif ((int) $u['is_suspended'] === 1) {
                $res = ['ok' => false, 'coins' => 0, 'error' => 'Tu cuenta está suspendida.'];
            } else {
                $up = $pdo->prepare('UPDATE users SET last_checkin_date = ? WHERE id = ? AND (last_checkin_date IS NULL OR last_checkin_date < ?)');
                $up->execute([$today, $userId, $today]);
                if ($up->rowCount() !== 1) {
                    $res = ['ok' => false, 'coins' => 0, 'error' => 'Ya reclamaste tu recompensa de hoy. Vuelve mañana.'];
                } else {
                    $coins = self::coins();
                    Coins::credit($userId, $coins, 'checkin', $today);
                    $res = ['ok' => true, 'coins' => $coins, 'error' => null];
                }
            }
            if ($own) {
                $pdo->commit();
            }
            return $res;
        } catch (Throwable $e) {
            if ($own) {
                try {
                    $pdo->rollBack();
                } catch (PDOException) {
                    // ya no había transacción activa (p. ej. falló el commit): se relanza el error original
                }
            }
            throw $e;
        }
    }
}
