<?php
declare(strict_types=1);

/**
 * Reglas de acceso: niveles de membresía, límite de páginas y plantillas (membresía + compra individual).
 *
 *   - Gratis:      is_premium = 0 y price_usd = 0                 -> cualquiera.
 *   - Compra:      price_usd > 0                                   -> membresía, o pago APPROVED de esa plantilla.
 *   - Solo socios: is_premium = 1 y price_usd = 0                  -> solo membresía.
 *
 * Un pago solo pasa a APPROVED desde el webhook firmado de Wompi (ExitosaAprobada);
 * aquí nunca se confía en nada que venga del cliente.
 */
final class Access
{
    /** Páginas activas permitidas sin membresía. */
    public const FREE_SITE_LIMIT = 3;

    /** Páginas que el plan gratuito puede crear por mes calendario (hora de El Salvador). */
    public const FREE_MONTHLY_PAGES = 3;

    /** Días de vida de una página sin membresía. */
    public const FREE_SITE_DAYS = 3;

    /** Subidas de HTML propio por mes calendario del plan gratuito (los planes de pago: membership_tiers.html_uploads_per_month). */
    public const FREE_MONTHLY_HTML_UPLOADS = 1;

    /** @return list<array<string,mixed>> niveles activos, del más bajo al más alto */
    public static function tiers(): array
    {
        return db()->query('SELECT * FROM membership_tiers WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function tier(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM membership_tiers WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** ¿Venció la membresía del usuario? NULL = no vence. Compara en UTC. */
    private static function lapsed(array $user, ?int $now = null): bool
    {
        $exp = $user['membership_expires_at'] ?? null;
        return $exp !== null && $exp !== '' && self::isExpired((string) $exp, $now);
    }

    /** ¿Tiene (o tuvo) algún plan asignado, vigente o no? */
    private static function hasPlanRecord(array $user): bool
    {
        return (int) ($user['is_premium'] ?? 0) === 1 || (int) ($user['membership_tier_id'] ?? 0) > 0;
    }

    /** Plan PRINCIPAL (comprado o asignado) vigente; null si no hay o venció. Lee la BD: no confía en la sesión. */
    public static function mainTier(array $user, ?int $now = null): ?array
    {
        $id = (int) ($user['membership_tier_id'] ?? 0);
        if ($id === 0 || self::lapsed($user, $now)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM membership_tiers WHERE id = ?');   // aunque se desactive, conserva sus beneficios
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Mejora TEMPORAL vigente (premio a creadores: users.bonus_tier_id / bonus_tier_expires_at); null si no hay o venció.
     * No es comprable ni renovable y no cuenta como «plan actual» en las pantallas de renovación (usa mainTier).
     */
    public static function bonusTier(array $user, ?int $now = null): ?array
    {
        $id = (int) ($user['bonus_tier_id'] ?? 0);
        $exp = $user['bonus_tier_expires_at'] ?? null;
        if ($id === 0 || $exp === null || $exp === '' || self::isExpired((string) $exp, $now)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM membership_tiers WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Nivel EFECTIVO del usuario (null = gratuito): el de mayor sort_order entre el plan principal vigente y la mejora
     * temporal vigente (a igualdad gana el principal). Con la mejora vencida o sin ella, es exactamente el principal.
     */
    public static function userTier(array $user, ?int $now = null): ?array
    {
        $main = self::mainTier($user, $now);
        $bonus = self::bonusTier($user, $now);
        if ($bonus !== null && ($main === null || (int) $bonus['sort_order'] > (int) $main['sort_order'])) {
            return $bonus;
        }
        return $main;
    }

    /** ¿Disfruta beneficios de miembro? Plan principal vigente o mejora temporal vigente. */
    public static function isMember(array $user, ?int $now = null): bool
    {
        return self::hasMainPlan($user, $now) || self::bonusTier($user, $now) !== null;
    }

    private static function hasMainPlan(array $user, ?int $now = null): bool
    {
        return self::hasPlanRecord($user) && !self::lapsed($user, $now);
    }

    /** ¿Tuvo plan PRINCIPAL y ya venció? (para «Tu plan venció»; la mejora temporal no cuenta) */
    public static function wasMember(array $user, ?int $now = null): bool
    {
        return self::hasPlanRecord($user) && self::lapsed($user, $now);
    }

    /** Vencimiento ('Y-m-d H:i:s' UTC) del plan efectivo; null si no hay plan efectivo o no vence. */
    public static function membershipExpiresAt(array $user, ?int $now = null): ?string
    {
        $exp = $user['membership_expires_at'] ?? null;
        return self::hasMainPlan($user, $now) && $exp !== null && $exp !== '' ? (string) $exp : null;
    }

    /** Días restantes (hacia arriba, mín. 0); null si no hay plan efectivo o no vence. */
    public static function membershipDaysLeft(array $user, ?int $now = null): ?int
    {
        $exp = self::membershipExpiresAt($user, $now);
        $ts = $exp === null ? false : strtotime($exp . ' UTC');
        return $ts === false ? null : max(0, (int) ceil(($ts - ($now ?? time())) / 86400));
    }

    /** Meses que dura un período del plan. */
    public static function tierMonths(array $tier): int
    {
        return max(1, (int) ($tier['duration_months'] ?? 1));
    }

    public static function tierDurationLabel(array $tier): string
    {
        $m = self::tierMonths($tier);
        return $m === 1 ? '1 mes' : $m . ' meses';
    }

    /** ¿Puede comprar/renovar este plan? Igual o superior a su plan PRINCIPAL vigente (la mejora temporal no cuenta). */
    public static function canPurchaseTier(array $user, array $tier, ?int $now = null): bool
    {
        $cur = self::mainTier($user, $now);
        return $cur === null || (int) $tier['sort_order'] >= (int) $cur['sort_order'];
    }

    /**
     * Aplica la compra APROBADA de un plan (llamar dentro de la transacción del webhook, una sola vez por pago).
     * Mismo plan vigente: suma el período al vencimiento actual. Plan superior o sin plan vigente: parte de ahora.
     * Nunca degrada a un plan inferior vigente.
     */
    public static function applyTierPurchase(PDO $pdo, int $userId, int $tierId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $st = $pdo->prepare('SELECT id, is_premium, membership_tier_id, membership_expires_at FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $user = $st->fetch();
        $st = $pdo->prepare('SELECT id, sort_order, duration_months FROM membership_tiers WHERE id = ?');
        $st->execute([$tierId]);
        $tier = $st->fetch();
        if (!$user || !$tier) {
            return;
        }
        $ts = $now->getTimestamp();
        $cur = null;
        if ((int) ($user['membership_tier_id'] ?? 0) > 0 && !self::lapsed($user, $ts)) {
            $st = $pdo->prepare('SELECT id, sort_order FROM membership_tiers WHERE id = ?');
            $st->execute([(int) $user['membership_tier_id']]);
            $cur = $st->fetch() ?: null;
        }
        $curRank = $cur !== null ? (int) $cur['sort_order'] : 0;
        if ((int) $tier['sort_order'] < $curRank) {
            return;   // no se degrada mientras el superior esté vigente
        }
        $base = $now;
        if ($cur !== null && (int) $cur['id'] === $tierId && !empty($user['membership_expires_at'])) {
            $base = new DateTimeImmutable((string) $user['membership_expires_at'], new DateTimeZone('UTC'));
        }
        $until = $base->modify('+' . self::tierMonths($tier) . ' months')->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET membership_tier_id = ?, is_premium = 1, membership_expires_at = ? WHERE id = ?')
            ->execute([$tierId, $until, $userId]);
    }

    public static function isAdFree(array $user, ?int $now = null): bool
    {
        $tier = self::userTier($user, $now);
        return $tier !== null && (int) $tier['ad_free'] === 1;
    }

    /** Plantillas extra compradas (cada una suma una página al permiso). */
    private static function extraSlots(int $userId): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'APPROVED' AND template_id IS NOT NULL");
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** Páginas activas que puede tener: límite del nivel (o el gratuito) + una por plantilla extra comprada. */
    public static function siteAllowance(array $user): int
    {
        $tier = self::userTier($user);
        $base = $tier !== null ? (int) $tier['max_sites'] : self::FREE_SITE_LIMIT;
        return $base + self::extraSlots((int) $user['id']);
    }

    /** Páginas ACTIVAS (no caducadas); las caducadas no ocupan cupo. */
    public static function siteCount(int $userId): int
    {
        $st = db()->prepare('SELECT COUNT(*) FROM user_sites WHERE user_id = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** ¿Sin plan efectivo (nunca tuvo o ya venció)? */
    public static function isFreePlan(array $user, ?int $now = null): bool
    {
        return self::userTier($user, $now) === null;
    }

    /**
     * Mes calendario en hora de El Salvador (UTC-6 fijo) que contiene $now.
     *
     * @return array{0:string,1:string} [inicio, próximo reinicio] en 'Y-m-d H:i:s' UTC
     */
    public static function monthBounds(?int $now = null): array
    {
        $sv = new DateTimeZone('America/El_Salvador');
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($sv)->modify('first day of this month')->setTime(0, 0);
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** Creaciones/renovaciones gratuitas (tier_id NULL) del mes actual. */
    public static function freeCreationsThisMonth(int $userId, ?int $now = null): int
    {
        [$from, $to] = self::monthBounds($now);
        $st = db()->prepare('SELECT COUNT(*) FROM site_creations WHERE user_id = ? AND tier_id IS NULL AND created_at >= ? AND created_at < ?');
        $st->execute([$userId, $from, $to]);
        return (int) $st->fetchColumn();
    }

    /**
     * Cupo mensual de plantillas "de membresía con cupo" (`templates.membership_unlocks = 1`): cuántas
     * plantillas DISTINTAS ha desbloqueado gratis el usuario este mes calendario. Sin plan efectivo,
     * `allowed` es 0 (el cupo es un beneficio de la membresía; sin ella se paga siempre en monedas).
     *
     * @return array{used:int, allowed:int, remaining:int, resets_at:string}
     */
    public static function templateUnlockUsage(array $user, ?int $now = null): array
    {
        $tier = self::userTier($user, $now);
        $allowed = $tier !== null ? (int) ($tier['template_unlocks_per_month'] ?? 0) : 0;
        [$from, $to] = self::monthBounds($now);
        $st = db()->prepare('SELECT COUNT(DISTINCT template_id) FROM template_unlocks WHERE user_id = ? AND created_at >= ? AND created_at < ?');
        $st->execute([(int) $user['id'], $from, $to]);
        $used = (int) $st->fetchColumn();
        return ['used' => $used, 'allowed' => $allowed, 'remaining' => max(0, $allowed - $used), 'resets_at' => $to];
    }

    /** ¿Ya desbloqueó ESTA plantilla por membresía este mes? (no consume cupo de nuevo si ya la tiene). */
    public static function hasUnlockedTemplateThisMonth(int $userId, int $templateId, ?int $now = null): bool
    {
        [$from, $to] = self::monthBounds($now);
        $st = db()->prepare('SELECT 1 FROM template_unlocks WHERE user_id = ? AND template_id = ? AND created_at >= ? AND created_at < ? LIMIT 1');
        $st->execute([$userId, $templateId, $from, $to]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Intenta cubrir con el cupo mensual de membresía el uso de una plantilla `membership_unlocks = 1`.
     * Llamar dentro de la MISMA transacción de `Sites::create()`/`renew()`, antes de cobrar monedas.
     * Si ya estaba desbloqueada este mes, o queda cupo (se registra el desbloqueo), devuelve true:
     * el llamador NO debe cobrar monedas. Si no hay membresía o el cupo ya se agotó, devuelve false:
     * el llamador cobra `price_coins` normalmente (fallback explícito, nunca un bloqueo duro).
     */
    public static function tryCoverByMembershipQuota(PDO $pdo, array $user, array $tpl, ?int $now = null): bool
    {
        if ((int) ($tpl['membership_unlocks'] ?? 0) !== 1) {
            return false;
        }
        $uid = (int) $user['id'];
        $tplId = (int) ($tpl['id'] ?? $tpl['template_id'] ?? 0);
        $tier = self::userTier($user, $now);
        if ($tier === null || $tplId === 0) {
            return false;
        }
        if (self::hasUnlockedTemplateThisMonth($uid, $tplId, $now)) {
            return true;   // ya cubierta este mes: no cuenta dos veces contra el cupo
        }
        // Bloquea la fila del usuario para serializar el conteo del cupo (mismo patrón que Sites::lockUser).
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$uid]);
        if (self::templateUnlockUsage($user, $now)['remaining'] <= 0) {
            return false;
        }
        $pdo->prepare('INSERT INTO template_unlocks (user_id, template_id, tier_id) VALUES (?, ?, ?)')
            ->execute([$uid, $tplId, (int) $tier['id']]);
        return true;
    }

    /**
     * Cupo mensual de subidas de HTML propio (mes calendario, hora de El Salvador). Cuenta TODAS las subidas del
     * mes contra el permiso del plan efectivo hoy (si el plan venció, vale el del plan gratuito).
     *
     * @return array{used:int, allowed:int, remaining:int, resets_at:string}
     */
    public static function htmlUploadUsage(array $user, ?int $now = null): array
    {
        $tier = self::userTier($user, $now);
        $allowed = $tier !== null ? max(0, (int) ($tier['html_uploads_per_month'] ?? 0)) : self::FREE_MONTHLY_HTML_UPLOADS;
        [$from, $to] = self::monthBounds($now);
        $st = db()->prepare('SELECT COUNT(*) FROM html_uploads WHERE user_id = ? AND created_at >= ? AND created_at < ?');
        $st->execute([(int) $user['id'], $from, $to]);
        $used = (int) $st->fetchColumn();
        return ['used' => $used, 'allowed' => $allowed, 'remaining' => max(0, $allowed - $used), 'resets_at' => $to];
    }

    /**
     * Registra una subida de HTML propio si queda cupo. Llamar DENTRO de la transacción de Sites::create()
     * (bloquea la fila del usuario para que dos subidas simultáneas no superen el cupo).
     * Devuelve el id de la subida, o null si el cupo del mes está agotado (no se registra nada).
     */
    public static function tryRecordHtmlUpload(PDO $pdo, array $user, string $sha256, int $bytes, ?int $now = null): ?int
    {
        $uid = (int) $user['id'];
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$uid]);
        if (self::htmlUploadUsage($user, $now)['remaining'] <= 0) {
            return null;
        }
        $tier = self::userTier($user, $now);
        $pdo->prepare('INSERT INTO html_uploads (user_id, tier_id, sha256, bytes) VALUES (?, ?, ?, ?)')
            ->execute([$uid, $tier !== null ? (int) $tier['id'] : null, $sha256, $bytes]);
        return (int) $pdo->lastInsertId();
    }

    /** Asocia la subida registrada con la página creada (el libro de subidas se conserva aunque la página se borre). */
    public static function linkHtmlUpload(PDO $pdo, int $uploadId, int $siteId): void
    {
        $pdo->prepare('UPDATE html_uploads SET site_id = ? WHERE id = ?')->execute([$siteId, $uploadId]);
    }

    /**
     * Uso del cupo de páginas. Plan gratuito: mode 'month' (creaciones del mes). Plan de pago: mode 'active'.
     *
     * @return array{mode:string, used:int, allowed:int, remaining:int, resets_at:?string}
     */
    public static function siteUsage(array $user, ?int $now = null): array
    {
        $uid = (int) $user['id'];
        if (self::isFreePlan($user, $now)) {
            $used = self::freeCreationsThisMonth($uid, $now);
            $allowed = self::FREE_MONTHLY_PAGES + self::extraSlots($uid);
            $reset = self::monthBounds($now)[1];
            $mode = 'month';
        } else {
            $used = self::siteCount($uid);
            $allowed = self::siteAllowance($user);
            $reset = null;
            $mode = 'active';
        }
        return ['mode' => $mode, 'used' => $used, 'allowed' => $allowed, 'remaining' => max(0, $allowed - $used), 'resets_at' => $reset];
    }

    public static function atSiteLimit(array $user, ?int $now = null): bool
    {
        return self::siteUsage($user, $now)['remaining'] === 0;
    }

    /** Fecha de reinicio legible en español (zona El Salvador, sin año): '1 de octubre'. */
    public static function monthResetLabel(?string $resetsAt): string
    {
        $ts = $resetsAt === null ? false : strtotime($resetsAt . ' UTC');
        $d = (new DateTimeImmutable('@' . ($ts === false ? time() : $ts)))->setTimezone(new DateTimeZone('America/El_Salvador'));
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return (int) $d->format('j') . ' de ' . $meses[(int) $d->format('n') - 1];
    }

    /** Días de vida de las páginas nuevas del usuario, según su plan. */
    public static function siteDays(array $user): int
    {
        $tier = self::userTier($user);
        return $tier !== null ? max(1, (int) $tier['site_days']) : self::FREE_SITE_DAYS;
    }

    /** Fecha de caducidad (UTC, 'Y-m-d H:i:s') de una página creada ahora. */
    public static function expiresAt(array $user, ?int $now = null): string
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) + self::siteDays($user) * 86400);
    }

    /** ¿Caducó? NULL = sin caducidad. Compara en UTC; expira cuando now > expires_at. */
    public static function isExpired(?string $expiresAt, ?int $now = null): bool
    {
        if ($expiresAt === null) {
            return false;
        }
        $ts = strtotime($expiresAt . ' UTC');
        return $ts !== false && ($now ?? time()) > $ts;
    }

    /**
     * Monedas que cuesta usar la plantilla. Ya pagada por separado en USD (compra individual
     * aprobada) no cobra monedas de nuevo: queda "incluida".
     */
    public static function coinCost(array $user, array $tpl): int
    {
        $cost = max(0, (int) ($tpl['price_coins'] ?? 0));
        if ($cost === 0) {
            return 0;
        }
        $tplId = (int) ($tpl['template_id'] ?? $tpl['id'] ?? 0);
        if (in_array($tplId, self::purchasedTemplateIds((int) $user['id']), true)) {
            return 0;   // comprada suelta en USD: ya incluida
        }
        if ((int) ($tpl['membership_unlocks'] ?? 0) === 1) {
            // Solo un vistazo (no consume cupo): decide si HOY se cubriría por membresía.
            // El consumo real ocurre en Access::tryCoverByMembershipQuota(), dentro de la transacción de Sites.
            $uid = (int) $user['id'];
            if (self::hasUnlockedTemplateThisMonth($uid, $tplId) || self::templateUnlockUsage($user)['remaining'] > 0) {
                return 0;
            }
        }
        return $cost;
    }

    /** Precio en centavos de una plantilla extra para este usuario (con el descuento de su nivel). */
    public static function extraTemplatePriceInCents(array $tpl, ?array $tier): int
    {
        $price = self::priceInCents($tpl);
        $pct   = $tier !== null ? (int) $tier['template_discount_pct'] : 0;
        return max(WOMPI_MIN_AMOUNT_IN_CENTS, (int) round($price * (100 - $pct) / 100));
    }

    /** Precio del nivel en centavos (0 si la fila es inválida). */
    public static function tierPriceInCents(array $tier): int
    {
        return wompi_usd_to_cents((string) $tier['price_usd']) ?? 0;
    }

    /** Precio individual en centavos de USD (0 = sin compra suelta). Acepta el DECIMAL(10,2) de la BD. */
    public static function priceInCents(array $tpl): int
    {
        return wompi_usd_to_cents((string) ($tpl['price_usd'] ?? '0')) ?? 0;
    }

    /** ¿La plantilla es de pago (membresía o compra)? */
    public static function isPaid(array $tpl): bool
    {
        return (int) ($tpl['is_premium'] ?? 0) === 1 || self::priceInCents($tpl) > 0;
    }

    /** ¿Se puede comprar suelta? (si no, solo entra con membresía) */
    public static function isPurchasable(array $tpl): bool
    {
        return self::priceInCents($tpl) > 0;
    }

    /**
     * @param array $user      fila de users (is_premium)
     * @param array $tpl       fila de templates
     * @param list<int> $owned ids de plantillas con pago aprobado (ver purchasedTemplateIds)
     */
    public static function canUse(array $user, array $tpl, array $owned): bool
    {
        if (!self::isPaid($tpl)) {
            return true;
        }
        if (in_array((int) $tpl['id'], $owned, true)) {
            return true;
        }
        if ((int) ($tpl['membership_unlocks'] ?? 0) === 1) {
            // Con cupo mensual: nunca queda del todo bloqueada si tiene un precio en monedas de respaldo.
            return self::isMember($user) || (int) ($tpl['price_coins'] ?? 0) > 0;
        }
        return self::isMember($user);
    }

    /** @return list<int> */
    public static function purchasedTemplateIds(int $userId): array
    {
        $st = db()->prepare("SELECT DISTINCT template_id FROM payments WHERE user_id = ? AND status = 'APPROVED' AND template_id IS NOT NULL");
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
