<?php
declare(strict_types=1);

require_once __DIR__ . '/Awards.php';
require_once __DIR__ . '/Referrals.php';

/**
 * Ciclo de vida de un pago: cumplimiento idempotente (plan, monedas, plantilla, cupón),
 * aprobación/anulación manual por un admin y compra gratuita con cupón del 100 %.
 * Solo SQL preparado; el llamador decide qué hacer con el resultado (respuesta HTTP, flash, log).
 */
final class Payments
{
    public const METHODS = ['WOMPI', 'MANUAL', 'PROMO'];

    /** Estados desde los que un admin puede aprobar manualmente. */
    public const MANUAL_APPROVABLE = ['PENDING', 'DECLINED', 'ERROR'];

    /**
     * Aplica el efecto de un pago APPROVED. Idempotente: la marca `fulfilled_at` se lee con FOR UPDATE,
     * así que dos llamadas (o dos webhooks a la vez) producen un solo efecto.
     * Si ya hay transacción abierta se reutiliza (el commit es del llamador); si no, abre y cierra la suya.
     *
     * @return array{ok:bool, already:bool, kind:string, error:?string, user_id:int, amount:int}
     */
    public static function fulfill(PDO $pdo, int $paymentId, string $source, ?int $adminId = null, string $note = ''): array
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT id, user_id, reference, amount_in_cents, status, promo_code, template_id, tier_id, coins, fulfilled_at
                                   FROM payments WHERE id = ? FOR UPDATE');
            $st->execute([$paymentId]);
            $pay = $st->fetch();
            $res = ['ok' => false, 'already' => false, 'kind' => '', 'error' => null, 'user_id' => 0, 'amount' => 0];
            if (!$pay) {
                $res['error'] = 'not_found';
            } else {
                $res['user_id'] = (int) $pay['user_id'];
                $res['amount']  = (int) $pay['amount_in_cents'];
                $res['kind']    = self::kind($pay);
                if ($pay['fulfilled_at'] !== null) {
                    $res['ok'] = $res['already'] = true;
                } elseif ($pay['status'] !== 'APPROVED') {
                    $res['error'] = 'not_approved';
                } else {
                    self::apply($pdo, $pay);
                    $pdo->prepare('UPDATE payments SET fulfilled_at = UTC_TIMESTAMP() WHERE id = ? AND fulfilled_at IS NULL')->execute([$paymentId]);
                    // Hitos de gasto acumulado (top de donadores): misma transacción; un fallo aquí nunca revierte el pago.
                    if ((int) $pay['amount_in_cents'] > 0) {
                        Awards::onPaymentFulfilled($pdo, (int) $pay['user_id']);
                        Referrals::onPaymentFulfilled($pdo, $pay);   // comisión al referente en la 1.ª compra real
                    }
                    $res['ok'] = true;
                }
            }
            if ($own) {
                $pdo->commit();
            }
            return $res;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Confirma un pago PENDING preguntando a Wompi (GET /EnlacePago/{link_id}) y, si está aprobado con el
     * monto exacto, lo marca APPROVED y aplica su efecto (idempotente). No depende del webhook.
     * Devuelve: approved | pending | mismatch | ignored | skip | error.
     *
     * @param array{id:int|string, reference:string, amount_in_cents:int|string, link_id:int|string|null} $pay
     */
    public static function reconcile(PDO $pdo, array $pay, ?WompiClient $client = null): string
    {
        $linkId = (int) ($pay['link_id'] ?? 0);
        if ($linkId <= 0 || ($client === null && !wompi_configured())) {
            return 'skip';
        }
        try {
            $st = ($client ?? WompiClient::fromEnv())->getPaymentLink($linkId);
        } catch (WompiException $e) {
            error_log('Wompi verificación ' . $pay['reference'] . ': ' . $e->getMessage());
            return 'error';
        }
        $r = WompiClient::parseLinkStatus($st);
        if (!$r['approved']) {
            error_log("Wompi verificación {$pay['reference']}: Wompi aún no la da por aprobada " . WompiClient::describeLink($st));
            return 'pending';
        }
        if ($r['amount_cents'] === null || $r['amount_cents'] !== (int) $pay['amount_in_cents']) {
            error_log("Wompi verificación {$pay['reference']}: monto no coincide (esperado {$pay['amount_in_cents']}, Wompi " . var_export($r['amount_cents'], true) . ')');
            return 'mismatch';
        }
        if (wompi_config()['env'] === 'production' && !$r['productive']) {
            error_log("Wompi verificación {$pay['reference']}: transacción no productiva rechazada en producción");
            return 'ignored';
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE payments SET status = 'APPROVED', wompi_transaction_id = ?, updated_at = UTC_TIMESTAMP()
                            WHERE id = ? AND status = 'PENDING'")
                ->execute([$r['transaction_id'] !== '' ? $r['transaction_id'] : null, (int) $pay['id']]);
            self::fulfill($pdo, (int) $pay['id'], 'wompi-api');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Wompi verificación ' . $pay['reference'] . ': ' . $e->getMessage());
            return 'error';
        }
        error_log("Wompi verificación {$pay['reference']}: aprobado y aplicado por consulta a la API");
        PurchaseNotice::email($pdo, (int) $pay['id']);   // ya confirmado y aplicado: correo a soporte y al comprador
        return 'approved';
    }

    /**
     * Reclama (atómico, 15 s de enfriamiento por pago) y reconcilia los pagos PENDING recientes de un usuario.
     * Se llama al volver de Wompi (tienda, crear, panel) y desde el webhook. Devuelve cuántos se aprobaron.
     */
    public static function reconcilePending(PDO $pdo, int $userId, ?WompiClient $client = null): int
    {
        $st = $pdo->prepare("SELECT id, reference, amount_in_cents, link_id FROM payments
                              WHERE user_id = ? AND status = 'PENDING' AND link_id IS NOT NULL
                                AND created_at > UTC_TIMESTAMP() - INTERVAL 6 HOUR ORDER BY id DESC LIMIT 3");
        $st->execute([$userId]);
        $n = 0;
        foreach ($st->fetchAll() as $pay) {
            if (self::claim($pdo, (int) $pay['id']) && self::reconcile($pdo, $pay, $client) === 'approved') {
                $n++;
            }
        }
        return $n;
    }

    /** Al volver de Wompi: confirma los pagos pendientes del usuario y, si alguno se aprobó, recarga la página con el estado nuevo. */
    public static function reconcileAndReload(PDO $pdo, int $userId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        PurchaseNotice::retryEmails($pdo, $userId);   // correos que no salieron la vez anterior (SMTP caído)
        if (self::reconcilePending($pdo, $userId) === 0) {
            return;
        }
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        redirect(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . ($qs !== '' ? '?' . $qs : ''));
    }

    /** Reserva la verificación de un pago: solo una petición cada 15 s (evita ráfagas hacia la API de Wompi). */
    public static function claim(PDO $pdo, int $paymentId): bool
    {
        $st = $pdo->prepare("UPDATE payments SET updated_at = UTC_TIMESTAMP()
                              WHERE id = ? AND status = 'PENDING' AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 SECOND");
        $st->execute([$paymentId]);
        return $st->rowCount() === 1;
    }

    /** @param array<string,mixed> $pay */
    public static function kind(array $pay): string
    {
        return ($pay['coins'] !== null && (int) $pay['coins'] > 0) ? 'coins' : ($pay['template_id'] !== null ? 'template' : 'tier');
    }

    /** @param array<string,mixed> $pay */
    private static function apply(PDO $pdo, array $pay): void
    {
        $uid = (int) $pay['user_id'];
        $ref = (string) $pay['reference'];
        // Recarga: el total lo fijó el servidor al crear el pago. Plantilla: queda APPROVED con template_id (basta).
        // Membresía: fija nivel y vencimiento sin bajarlo; un pago previo a los niveles (sin tier) equivale a Eterno.
        if (self::kind($pay) === 'coins') {
            Coins::credit($uid, (int) $pay['coins'], 'topup', $ref);
        } elseif ($pay['template_id'] === null) {
            $tierId = $pay['tier_id'] ?? $pdo->query("SELECT id FROM membership_tiers WHERE slug = 'eterno'")->fetchColumn();
            Access::applyTierPurchase($pdo, $uid, (int) $tierId);
            $st = $pdo->prepare('SELECT bonus_coins FROM membership_tiers WHERE id = ?');
            $st->execute([$tierId]);
            if (($bonus = (int) $st->fetchColumn()) > 0) {
                Coins::credit($uid, $bonus, 'tier_bonus', $ref);
            }
        }
        // El contador del código y el canje del usuario solo avanzan con pagos realmente cumplidos.
        // FOR UPDATE bloquea la fila del cupón: si dos pagos con el mismo código de un solo uso llegan
        // a cumplirse casi a la vez, el segundo ve max_uses ya agotado dentro de esta misma transacción
        // (el pago ya se cobró por el monto con descuento; solo se le niega el registro del canje).
        if (!empty($pay['promo_code'])) {
            $st = $pdo->prepare('SELECT id, max_uses, uses FROM promos WHERE code = ? FOR UPDATE');
            $st->execute([$pay['promo_code']]);
            $promo = $st->fetch();
            if ($promo && ((int) $promo['max_uses'] === 0 || (int) $promo['uses'] < (int) $promo['max_uses'])) {
                $ins = $pdo->prepare('INSERT IGNORE INTO promo_redemptions (promo_id, user_id, payment_id) VALUES (?, ?, ?)');
                $ins->execute([(int) $promo['id'], $uid, (int) $pay['id']]);
                if ($ins->rowCount() === 1) {
                    $pdo->prepare('UPDATE promos SET uses = uses + 1 WHERE id = ?')->execute([(int) $promo['id']]);
                }
            }
        }
    }

    /**
     * Aprobación manual (transferencia verificada, etc.): PENDING/DECLINED/ERROR -> APPROVED + cumplimiento.
     *
     * @return array{ok:bool, error:?string, payment:?array<string,mixed>}
     */
    public static function approveManually(PDO $pdo, int $paymentId, int $adminId, string $note): array
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            return ['ok' => false, 'error' => 'La nota es obligatoria (máximo 255 caracteres).', 'payment' => null];
        }
        $pdo->beginTransaction();
        try {
            $pay = self::lock($pdo, $paymentId);
            if (!$pay) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'El pago no existe.', 'payment' => null];
            }
            if ($pay['fulfilled_at'] !== null || $pay['status'] === 'APPROVED') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Este pago ya está aprobado y cumplido; no se puede aprobar otra vez.', 'payment' => $pay];
            }
            if (!in_array($pay['status'], self::MANUAL_APPROVABLE, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Un pago anulado no se puede aprobar.', 'payment' => $pay];
            }
            $pdo->prepare("UPDATE payments SET status = 'APPROVED', method = 'MANUAL', approved_by = ?, admin_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$adminId, $note, $paymentId]);
            $r = self::fulfill($pdo, $paymentId, 'manual', $adminId, $note);
            if (!$r['ok'] || $r['already']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'No se pudo cumplir el pago.', 'payment' => $pay];
            }
            $pdo->commit();
            return ['ok' => true, 'error' => null, 'payment' => $pay];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payments::approveManually: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error interno al aprobar el pago.', 'payment' => null];
        }
    }

    /**
     * Anula (VOIDED) un pago aún no cumplido, con nota.
     *
     * @return array{ok:bool, error:?string, payment:?array<string,mixed>}
     */
    public static function void(PDO $pdo, int $paymentId, int $adminId, string $note): array
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            return ['ok' => false, 'error' => 'La nota es obligatoria (máximo 255 caracteres).', 'payment' => null];
        }
        $pdo->beginTransaction();
        try {
            $pay = self::lock($pdo, $paymentId);
            if (!$pay) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'El pago no existe.', 'payment' => null];
            }
            if ($pay['fulfilled_at'] !== null || $pay['status'] === 'APPROVED') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Un pago ya cumplido no se puede anular.', 'payment' => $pay];
            }
            if ($pay['status'] === 'VOIDED') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'El pago ya está anulado.', 'payment' => $pay];
            }
            $pdo->prepare("UPDATE payments SET status = 'VOIDED', approved_by = ?, admin_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$adminId, $note, $paymentId]);
            $pdo->commit();
            return ['ok' => true, 'error' => null, 'payment' => $pay];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payments::void: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error interno al anular el pago.', 'payment' => null];
        }
    }

    /** @return array<string,mixed>|null */
    private static function lock(PDO $pdo, int $paymentId): ?array
    {
        $st = $pdo->prepare('SELECT id, user_id, amount_in_cents, status, fulfilled_at, tier_id, template_id, coins FROM payments WHERE id = ? FOR UPDATE');
        $st->execute([$paymentId]);
        return $st->fetch() ?: null;
    }

    // ---------------------------------------------------------- cupones ---

    /** Precio tras aplicar el descuento: nunca negativo; 100 % = 0; por debajo, con el mínimo de Wompi. */
    public static function discountedCents(int $cents, int $pct): int
    {
        $pct = max(0, min(100, $pct));
        if ($pct >= 100) {
            return 0;
        }
        return max(WOMPI_MIN_AMOUNT_IN_CENTS, (int) round(max(0, $cents) * (100 - $pct) / 100));
    }

    /** ¿El cupón vale para este producto? (alcance y plan concreto). */
    public static function promoApplies(array $promo, ?int $tierId, bool $isTemplate): bool
    {
        return match ((string) ($promo['scope'] ?? 'all')) {
            'tiers'     => !$isTemplate && $tierId !== null && ($promo['tier_id'] === null || (int) $promo['tier_id'] === $tierId),
            'templates' => $isTemplate,
            default     => true,
        };
    }

    public static function alreadyRedeemed(PDO $pdo, int $promoId, int $userId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM promo_redemptions WHERE promo_id = ? AND user_id = ?');
        $st->execute([$promoId, $userId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Compra con cupón del 100 %: crea el pago (monto 0, PROMO, APPROVED) y lo cumple, todo en una
     * transacción con el cupón bloqueado (respeta max_uses aunque lleguen dos a la vez). Sin Wompi.
     *
     * @param array<string,mixed> $promo fila de promos
     * @return array{ok:bool, error:?string, payment_id:?int, kind:string}
     */
    public static function redeemFree(PDO $pdo, int $userId, array $promo, ?int $tierId, ?int $templateId): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'payment_id' => null, 'kind' => ''];
        if ((int) $promo['discount_percent'] < 100 || ($tierId === null) === ($templateId === null)) {
            return $fail('Solicitud inválida.');
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, code, is_active, expires_at, max_uses, uses FROM promos WHERE id = ? FOR UPDATE');
            $st->execute([(int) $promo['id']]);
            $p = $st->fetch();
            if (!$p || (int) $p['is_active'] !== 1
                || ($p['expires_at'] !== null && $p['expires_at'] < gmdate('Y-m-d'))
                || ((int) $p['max_uses'] > 0 && (int) $p['uses'] >= (int) $p['max_uses'])) {
                $pdo->rollBack();
                return $fail('Ese código de promoción no es válido, ya caducó o se agotó.');
            }
            if (self::alreadyRedeemed($pdo, (int) $p['id'], $userId)) {
                $pdo->rollBack();
                return $fail('Ya usaste este código de promoción.');
            }
            $ref = 'LP-' . $userId . '-' . bin2hex(random_bytes(8));
            $pdo->prepare("INSERT INTO payments (user_id, reference, amount_in_cents, currency, status, method, promo_code, template_id, tier_id)
                           VALUES (?, ?, 0, ?, 'APPROVED', 'PROMO', ?, ?, ?)")
                ->execute([$userId, $ref, WOMPI_CURRENCY, $p['code'], $templateId, $tierId]);
            $paymentId = (int) $pdo->lastInsertId();
            $r = self::fulfill($pdo, $paymentId, 'promo');
            if (!$r['ok']) {
                $pdo->rollBack();
                return $fail('No se pudo aplicar el cupón.');
            }
            $pdo->commit();
            return ['ok' => true, 'error' => null, 'payment_id' => $paymentId, 'kind' => $r['kind']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payments::redeemFree: ' . $e->getMessage());
            return $fail('No pudimos aplicar el cupón. Inténtalo de nuevo.');
        }
    }
}
