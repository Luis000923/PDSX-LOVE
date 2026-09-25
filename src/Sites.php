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
     * @return array{slug?:string, error?:string}
     */
    public static function create(array $user, array $tpl, array $data): array
    {
        $pdo  = db();
        $uid  = (int) $user['id'];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $pdo->beginTransaction();
        try {
            $fresh = self::lockUser($uid);
            if (Access::atSiteLimit($fresh)) {
                $pdo->rollBack();
                return ['error' => self::limitMessage($fresh) ?? 'Alcanzaste el límite de páginas activas de tu plan. Espera a que expire alguna, elimínala o sube de plan.'];
            }

            $expires = Access::expiresAt($fresh);
            $slug = null;
            for ($i = 0; $i < 5 && $slug === null; $i++) {   // reintenta ante colisión de slug
                $try = '';
                for ($j = 0; $j < 8; $j++) {
                    $try .= self::ALPHABET[random_int(0, 30)];
                }
                try {
                    $pdo->prepare('INSERT INTO user_sites (user_id, template_id, slug, data, expires_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$uid, (int) $tpl['id'], $try, $json, $expires]);
                    $slug = $try;
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

            if (!Coins::spend($uid, Access::coinCost($fresh, $tpl), 'site_create', $slug)) {
                $pdo->rollBack();
                return ['error' => 'No tienes monedas suficientes para esta plantilla. Recarga y vuelve a intentarlo.'];
            }
            self::logCreation($fresh, 'create');
            $pdo->commit();
            return ['slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
        try {
            $fresh = self::lockUser($uid);
            $st = $pdo->prepare('SELECT s.id, s.slug, s.expires_at, t.price_coins, t.id AS template_id
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
            if (!Coins::spend($uid, Access::coinCost($fresh, $site), 'site_renew', (string) $site['slug'])) {
                $pdo->rollBack();
                return 'No tienes monedas suficientes para renovar. Recarga y vuelve a intentarlo.';
            }
            $pdo->prepare('UPDATE user_sites SET expires_at = ? WHERE id = ?')->execute([Access::expiresAt($fresh), $siteId]);
            self::logCreation($fresh, 'renew');
            $pdo->commit();
            return null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
