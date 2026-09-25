<?php
declare(strict_types=1);

/**
 * Alta y renovación de páginas de pareja: límite del plan, cobro en monedas y caducidad.
 *
 * Todo ocurre en una transacción con la fila del usuario bloqueada (FOR UPDATE), de modo que
 * dos peticiones simultáneas no pueden superar el límite ni gastar el mismo saldo dos veces.
 */
final class Sites
{
    /** Alfabeto del slug público (sin caracteres ambiguos). */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Crea una página. Devuelve ['slug' => ...] o ['error' => mensaje].
     *
     * @param array<string,mixed> $user fila de users
     * @param array<string,mixed> $tpl  fila de templates (id, price_coins, ...)
     * @param array<string,string> $data campos ya validados por Template::sanitize()
     * @param (callable(PDO,int,string,array<string,mixed>):?string)|null $onCreated se invoca dentro de la transacción tras
     *        insertar la página y antes de cobrar; un string devuelto = error (rollback), una excepción = rollback y se relanza
     * @return array{slug?:string, error?:string}
     */
    public static function create(array $user, array $tpl, array $data, ?callable $onCreated = null): array
    {
        $pdo  = db();
        $uid  = (int) $user['id'];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $pdo->beginTransaction();
        $slug = null;
        $siteId = 0;
        $settleFor = null;
        try {
            $fresh = self::lockUser($uid);
            if (Access::atSiteLimit($fresh)) {
                $pdo->rollBack();
                return ['error' => self::limitMessage($fresh) ?? 'Alcanzaste el límite de páginas activas de tu plan. Espera a que expire alguna, elimínala o sube de plan.'];
            }

            $expires = Access::expiresAt($fresh);
            for ($i = 0; $i < 5 && $slug === null; $i++) {   // reintenta ante colisión de slug
                $try = '';
                for ($j = 0; $j < 8; $j++) {
                    $try .= self::ALPHABET[random_int(0, 30)];
                }
                try {
                    $pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data, expires_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$uid, (int) $tpl['id'], $try, $json, $expires]);
                    $slug = $try;
                    $siteId = (int) $pdo->lastInsertId();
                } catch (PDOException $ex) {
                    if ($ex->getCode() !== '23000') {
                        throw $ex;
                    }
                }
            }
            if ($slug === null) {
                $pdo->rollBack();
                return ['error' => 'No se pudo crear la página. Inténtalo otra vez.'];
            }

            if ($onCreated !== null) {
                $msg = $onCreated($pdo, $siteId, $slug, $fresh);
                if ($msg !== null) {
                    $pdo->rollBack();
                    self::purgeFiles($slug);
                    return ['error' => $msg];
                }
            }

            // Plantillas públicas de usuario: solo aprobadas y activas (comprobación en servidor, aunque se fuerce el id).
            $utpl = null;
            if (($tpl['kind'] ?? 'utpl') === 'utpl') {
                $utpl = Creators::saleContext($pdo, (int) $tpl['id']);
                if ($utpl !== null && ($utpl['status'] !== 'approved' || !$utpl['active'])) {
                    $pdo->rollBack();
                    self::purgeFiles($slug);
                    return ['error' => 'Esta plantilla ya no está disponible.'];
                }
            }
            $charge = self::charge($pdo, $fresh, $tpl, 'site_create', $slug);
            if ($charge === null) {
                $pdo->rollBack();
                self::purgeFiles($slug);
                return ['error' => 'No tienes monedas suficientes para esta plantilla. Recarga y vuelve a intentarlo.'];
            }
            if ($utpl !== null) {
                $settleFor = CreatorEarnings::record($pdo, $utpl, $uid, $siteId, 'create:' . $siteId, $charge['kind'], $charge['base']);
            }
            self::logCreation($fresh, 'create');
            $pdo->commit();
            if ($settleFor !== null) {
                CreatorEarnings::settle($settleFor);   // fuera de la transacción del comprador (sin bloqueos cruzados)
            }
            return ['slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($slug !== null) {
                self::purgeFiles($slug);
            }
            throw $e;
        }
    }

    /**
     * Borra las carpetas de una página (fotos públicas y HTML propio privado). Seguro: valida el slug, usa rutas
     * absolutas propias, exige que el realpath quede dentro de la raíz esperada y no sigue enlaces simbólicos.
     */
    public static function purgeFiles(string $slug): void
    {
        if (!preg_match(TemplateImages::SLUG_RE, $slug)) {
            return;
        }
        foreach ([ROOT . '/public/uploads/sites', ROOT . '/storage/user_html'] as $base) {
            $baseReal = realpath($base);
            $dir = $base . '/' . $slug;
            if ($baseReal === false || is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $real = realpath($dir);
            if ($real === false || $real !== $baseReal . DIRECTORY_SEPARATOR . $slug) {
                continue;
            }
            self::rmTree($real);
        }
    }

    private static function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $dir . '/' . $n;
            if (is_dir($p) && !is_link($p)) {
                self::rmTree($p);
            } else {
                @unlink($p);   // enlaces: se borra el enlace, nunca su destino
            }
        }
        @rmdir($dir);
    }

    /**
     * Renueva una página (caducada o vigente): cobra de nuevo la plantilla y reinicia la caducidad
     * con la duración del plan actual del usuario. Una página vigente no ocupa un cupo nuevo.
     *
     * @param array<string,mixed> $user
     * @return string|null mensaje de error, o null si se renovó
     */
    public static function renew(array $user, int $siteId): ?string
    {
        $pdo = db();
        $uid = (int) $user['id'];

        $pdo->beginTransaction();
        $settleFor = null;
        try {
            $fresh = self::lockUser($uid);
            $st = $pdo->prepare('SELECT s.id, s.slug, s.expires_at, t.price_coins, t.membership_unlocks, t.id AS template_id, t.kind
                                   FROM user_sites s JOIN templates t ON t.id = s.template_id
                                  WHERE s.id = ? AND s.user_id = ?');
            $st->execute([$siteId, $uid]);
            $site = $st->fetch();
            if (!$site) {
                $pdo->rollBack();
                return 'Página no encontrada.';
            }
            if (Access::isFreePlan($fresh)) {   // plan gratuito: renovar consume cupo mensual
                if (Access::atSiteLimit($fresh)) {
                    $pdo->rollBack();
                    return self::limitMessage($fresh);
                }
            } elseif (Access::isExpired($site['expires_at'] === null ? null : (string) $site['expires_at']) && Access::atSiteLimit($fresh)) {
                $pdo->rollBack();
                return 'Alcanzaste el límite de páginas activas de tu plan; elimina una o sube de plan para renovar.';
            }
            $utpl = $site['kind'] === 'utpl' ? Creators::saleContext($pdo, (int) $site['template_id']) : null;
            if ($utpl !== null && !in_array($utpl['status'], ['approved', 'withdrawn'], true)) {
                $pdo->rollBack();
                return 'Esta plantilla ya no está disponible.';
            }
            $charge = self::charge($pdo, $fresh, $site, 'site_renew', (string) $site['slug']);
            if ($charge === null) {
                $pdo->rollBack();
                return 'No tienes monedas suficientes para renovar. Recarga y vuelve a intentarlo.';
            }
            if ($utpl !== null) {   // record() ignora las retiradas: solo las aprobadas generan ganancia
                $settleFor = CreatorEarnings::record($pdo, $utpl, $uid, $siteId, 'renew:' . $siteId . ':' . gmdate('YmdHis'), $charge['kind'], $charge['base']);
            }
            $pdo->prepare('UPDATE user_sites SET expires_at = ? WHERE id = ?')->execute([Access::expiresAt($fresh), $siteId]);
            self::logCreation($fresh, 'renew');
            $pdo->commit();
            if ($settleFor !== null) {
                CreatorEarnings::settle($settleFor);
            }
            return null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cubre por cupo de membresía (si aplica) o cobra monedas. null = saldo insuficiente (sin cambios).
     * Devuelve el tipo de cobro para el reparto a creadores: 'quota' solo si este uso CONSUME cupo nuevo (base = precio
     * nominal en monedas); 'coins' con las monedas realmente gastadas; base 0 si no se cobró nada.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $tpl
     * @return array{kind:string, base:int}|null
     */
    private static function charge(PDO $pdo, array $user, array $tpl, string $reason, string $ref): ?array
    {
        $kind = 'coins';
        $tplId = (int) ($tpl['id'] ?? $tpl['template_id'] ?? 0);
        $nominal = max(0, (int) ($tpl['price_coins'] ?? 0));
        $newQuota = false;
        // Plantillas "de membresía con cupo": intenta cubrirla con el cupo mensual antes de cobrar monedas
        // (si no hay cupo o no es miembro, no hace nada y coinCost() cobra normal en monedas).
        if ((int) ($tpl['membership_unlocks'] ?? 0) === 1) {
            $already = Access::hasUnlockedTemplateThisMonth((int) $user['id'], $tplId);
            $newQuota = Access::tryCoverByMembershipQuota($pdo, $user, $tpl) && !$already;
        }
        $cost = Access::coinCost($user, $tpl);
        if (!Coins::spend((int) $user['id'], $cost, $reason, $ref)) {
            return null;
        }
        if ($newQuota && $cost === 0) {
            return ['kind' => 'quota', 'base' => $nominal];
        }
        return ['kind' => $kind, 'base' => $cost];
    }

    /** Mensaje de cuota mensual agotada (solo plan gratuito); null si no aplica. */
    private static function limitMessage(array $user): ?string
    {
        if (!Access::isFreePlan($user)) {
            return null;
        }
        $when = Access::monthResetLabel(Access::monthBounds()[1]);
        return 'Usaste tus páginas gratuitas de este mes. Se reinician el ' . $when . '; o sube de plan.';
    }

    /** Registra la creación/renovación (auditoría y cuota mensual; borrar la página no la elimina). */
    private static function logCreation(array $user, string $kind): void
    {
        $tier = Access::userTier($user);
        db()->prepare('INSERT INTO site_creations (user_id, tier_id, kind) VALUES (?, ?, ?)')
            ->execute([(int) $user['id'], $tier === null ? null : (int) $tier['id'], $kind]);
    }

    /** @return array<string,mixed> fila de users bloqueada hasta el fin de la transacción */
    private static function lockUser(int $uid): array
    {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$uid]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('Usuario inexistente.');
        }
        return $row;
    }
}
