<?php
declare(strict_types=1);

/**
 * Economía interna: saldo de monedas, libro de movimientos y paquetes de recarga.
 *
 * El saldo vive en users.coins (CHECK >= 0). Gastar es un UPDATE condicional atómico
 * (`coins >= ?`), así dos peticiones simultáneas nunca dejan el saldo en negativo. Cada
 * movimiento queda en coin_transactions. Las funciones que mueven saldo NO abren transacción:
 * el llamador decide el alcance (crear página = cobro + alta en la misma transacción).
 */
final class Coins
{
    /** Monedas base por cada dólar de una recarga (antes de la bonificación del plan). */
    public const PER_USD = 10;

    /** Paquetes de recarga disponibles, en centavos de USD. El cliente solo elige uno de esta lista. */
    public const PACKS_CENTS = [100, 300, 500, 1000];

    /**
     * Bono % propio de cada paquete (lo recibe cualquiera, sin membresía). Crece con el monto para que
     * comprar más NUNCA dé menos monedas por dólar; el % de la membresía se suma a este.
     */
    public const PACK_BONUS_PCT = [100 => 0, 300 => 15, 500 => 20, 1000 => 25];

    /** % de bono del paquete (0 si no es un paquete válido). */
    public static function packBonusPct(int $cents): int
    {
        return self::PACK_BONUS_PCT[$cents] ?? 0;
    }

    /** % total de bono: el del paquete + el de la membresía (suma directa). */
    public static function totalBonusPct(int $cents, ?array $tier): int
    {
        return self::packBonusPct($cents) + ($tier !== null ? (int) $tier['topup_bonus_pct'] : 0);
    }

    /** Monedas de un paquete: base + bono total (redondeo hacia abajo, todo en enteros). */
    public static function packCoins(int $cents, ?array $tier): int
    {
        $base = intdiv($cents * self::PER_USD, 100);
        return $base + intdiv($base * self::totalBonusPct($cents, $tier), 100);
    }

    /** ¿Es un paquete válido? (validación estricta contra la lista fija) */
    public static function isPack(int $cents): bool
    {
        return in_array($cents, self::PACKS_CENTS, true);
    }

    public static function balance(int $userId): int
    {
        $st = db()->prepare('SELECT coins FROM users WHERE id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** Abona monedas (amount > 0) y anota el movimiento. */
    public static function credit(int $userId, int $amount, string $reason, string $ref = ''): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('El abono debe ser positivo.');
        }
        $pdo = db();
        $pdo->prepare('UPDATE users SET coins = coins + ? WHERE id = ?')->execute([$amount, $userId]);
        self::log($userId, $amount, $reason, $ref);
    }

    /** Gasta monedas. false (sin cambios) si el saldo no alcanza. */
    public static function spend(int $userId, int $amount, string $reason, string $ref = ''): bool
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('El gasto no puede ser negativo.');
        }
        if ($amount === 0) {
            return true;
        }
        $st = db()->prepare('UPDATE users SET coins = coins - ? WHERE id = ? AND coins >= ?');
        $st->execute([$amount, $userId, $amount]);
        if ($st->rowCount() !== 1) {
            return false;
        }
        self::log($userId, -$amount, $reason, $ref);
        return true;
    }

    private static function log(int $userId, int $delta, string $reason, string $ref): void
    {
        db()->prepare('INSERT INTO coin_transactions (user_id, delta, balance_after, reason, ref)
                       SELECT id, ?, coins, ?, ? FROM users WHERE id = ?')
            ->execute([$delta, $reason, mb_substr($ref, 0, 191), $userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $userId, int $limit = 20): array
    {
        $st = db()->prepare('SELECT delta, balance_after, reason, ref, created_at FROM coin_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute([$userId]);
        return $st->fetchAll();
    }
}
