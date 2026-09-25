<?php
declare(strict_types=1);

/**
 * Referidos orgánicos: cada usuario tiene un código (`?ref=CODIGO` en el registro). Cuando el referido cumple su
 * PRIMER pago real (Wompi o manual, monto > 0), quien lo invitó recibe un % de monedas, en la misma transacción
 * del pago. Idempotencia: referral_rewards.referred_id es UNIQUE, así que solo el primer pago puede premiar.
 */
final class Referrals
{
    public const DEFAULT_PCT = 10;
    public const MAX_PCT     = 50;
    public const MAX_REWARD  = 500;   // tope de monedas por referido
    private const ALPHABET   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin 0/O/1/I
    public const CODE_LEN    = 8;

    public static function pct(): int
    {
        $raw = trim(Admin::setting('referral_pct'));
        return $raw !== '' && ctype_digit($raw) ? min(self::MAX_PCT, (int) $raw) : self::DEFAULT_PCT;
    }

    /** Normaliza un código recibido (mayúsculas, formato estricto). '' si no es válido. */
    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[' . self::ALPHABET . ']{' . self::CODE_LEN . '}$/', $code) === 1 ? $code : '';
    }

    private static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }

    /** Código del usuario; lo crea la primera vez (cuentas anteriores a esta función incluidas). */
    public static function codeFor(int $userId): string
    {
        $pdo = db();
        $st = $pdo->prepare('SELECT referral_code FROM users WHERE id = ?');
        $st->execute([$userId]);
        $code = $st->fetchColumn();
        if (is_string($code) && $code !== '') {
            return $code;
        }
        for ($try = 0; $try < 5; $try++) {
            $new = self::generate();
            // Condicional: si otra petición ya lo asignó, no se pisa.
            $up = $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ? AND referral_code IS NULL');
            try {
                $up->execute([$new, $userId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {   // colisión de código: otro intento
                    continue;
                }
                throw $e;
            }
            break;
        }
        $st->execute([$userId]);
        return (string) $st->fetchColumn();
    }

    /** Id del dueño de un código, o null. */
    public static function ownerOf(string $code): ?int
    {
        $code = self::normalize($code);
        if ($code === '') {
            return null;
        }
        $st = db()->prepare('SELECT id FROM users WHERE referral_code = ?');
        $st->execute([$code]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Asocia al usuario recién creado con quien lo invitó. Sin efecto si el código no existe o es el propio. */
    public static function attach(PDO $pdo, int $newUserId, string $code): bool
    {
        $owner = self::ownerOf($code);
        if ($owner === null || $owner === $newUserId) {
            return false;
        }
        $st = $pdo->prepare('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL');
        $st->execute([$owner, $newUserId]);
        return $st->rowCount() === 1;
    }

    /** Monedas de comisión para una compra: % sobre su equivalente en monedas (mínimo 1, tope MAX_REWARD). */
    public static function commission(int $baseCoins): int
    {
        if ($baseCoins <= 0) {
            return 0;
        }
        return min(self::MAX_REWARD, max(1, intdiv($baseCoins * self::pct(), 100)));
    }

    /**
     * Gancho de Payments::fulfill (transacción abierta): premia al referente en el primer pago real del referido.
     * Nunca lanza: un fallo aquí no debe deshacer el pago.
     *
     * @param array<string,mixed> $pay Fila del pago (id, user_id, amount_in_cents, coins)
     * @return int monedas concedidas (0 si no aplica)
     */
    public static function onPaymentFulfilled(PDO $pdo, array $pay): int
    {
        try {
            $cents = (int) $pay['amount_in_cents'];
            if ($cents <= 0) {
                return 0;
            }
            $st = $pdo->prepare('SELECT referred_by FROM users WHERE id = ?');
            $st->execute([(int) $pay['user_id']]);
            $referrer = $st->fetchColumn();
            if ($referrer === false || $referrer === null || (int) $referrer === (int) $pay['user_id']) {
                return 0;
            }
            $base  = ($pay['coins'] ?? null) !== null && (int) $pay['coins'] > 0 ? (int) $pay['coins'] : intdiv($cents * Coins::PER_USD, 100);
            $coins = self::commission($base);
            if ($coins <= 0) {
                return 0;
            }
            $ins = $pdo->prepare('INSERT IGNORE INTO referral_rewards (referrer_id, referred_id, payment_id, coins) VALUES (?, ?, ?, ?)');
            $ins->execute([(int) $referrer, (int) $pay['user_id'], (int) $pay['id'], $coins]);
            if ($ins->rowCount() !== 1) {
                return 0;   // ya hubo una primera compra premiada
            }
            Coins::credit((int) $referrer, $coins, 'referral', 'ref-u' . (int) $pay['user_id']);
            return $coins;
        } catch (Throwable $e) {
            error_log('Referrals::onPaymentFulfilled: ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array{invited:int, rewarded:int, coins:int} */
    public static function stats(int $userId): array
    {
        $pdo = db();
        $a = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
        $a->execute([$userId]);
        $b = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(coins), 0) FROM referral_rewards WHERE referrer_id = ?');
        $b->execute([$userId]);
        [$n, $c] = $b->fetch(PDO::FETCH_NUM);
        return ['invited' => (int) $a->fetchColumn(), 'rewarded' => (int) $n, 'coins' => (int) $c];
    }
}
