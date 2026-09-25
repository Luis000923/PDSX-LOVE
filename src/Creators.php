<?php
declare(strict_types=1);

require_once __DIR__ . '/Awards.php';
require_once __DIR__ . '/CreatorEarnings.php';

/**
 * Plantillas públicas de usuario (templates.kind = 'utpl'): configuración, envío, revisión y consultas.
 *
 * Ciclo: pending -> approved | rejected; approved -> withdrawn; rejected/withdrawn -> pending (reenvío con archivo nuevo).
 * Una plantilla que no está 'approved' NUNCA se ofrece (Galería, crear, vista previa) ni se puede usar aunque se fuerce el id.
 * Las páginas ya creadas con una plantilla retirada siguen vivas (view/frame aceptan withdrawn); con una rechazada no.
 * El HTML vive FUERA del webroot (storage/user_templates/{slug}.html); un reenvío se guarda como {slug}.new.html y solo
 * reemplaza al vigente al aprobarse, así el contenido sin revisar nunca llega a páginas ya publicadas.
 * Ganancias: CreatorEarnings. Premios: CreatorAwards. Docs: docs/CREADORES.md.
 */
final class Creators
{
    public const CONFIG_KEY = 'creators_config';
    public const LAUNCH_KEY = 'creators_launch_month';
    public const SLUG_RE = '/^u-[0-9a-f]{10}$/D';
    public const MAX_PHOTOS = 12;

    /** Filtro SQL de plantillas OFRECIDAS al público (alias `t`): activas, no ocultas y, si son de usuario, aprobadas. */
    public const PUBLIC_WHERE = "t.is_active = 1 AND t.kind <> 'user' AND (t.kind <> 'utpl' OR t.review_status = 'approved')";

    public const BADGE_CHOICES = ['', 'creador', 'creador_estrella', 'colaborador_mes'];

    /** Valores por defecto. Todo editable desde admin/creators.php (validado en validateConfig). */
    public const DEFAULTS = [
        'share_pct'             => 30,
        'share_on_quota_unlock' => true,
        'min_price'             => 5,
        'max_price'             => 200,
        'max_pending'           => 3,
        'max_templates'         => 30,
        'success_uses'          => 5,
        'milestones'            => [
            ['count' => 1,  'coins' => 20,  'badge' => 'creador'],
            ['count' => 3,  'coins' => 60,  'badge' => ''],
            ['count' => 5,  'coins' => 120, 'badge' => 'creador_estrella'],
            ['count' => 10, 'coins' => 300, 'badge' => ''],
        ],
        'month_min_templates'   => 3,
        'month_min_uses'        => 20,
        'month_coins'           => 80,
        'top_tier_slug'         => 'pareja',
        'top_tier_days'         => 7,
    ];

    private static ?array $cfg = null;

    // -------------------------------------------------------- configuración ---

    /** @return array<string,mixed> configuración vigente (validada; ante datos inválidos, los valores por defecto) */
    public static function config(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $cfg = self::DEFAULTS;
        $json = Awards::setting(self::CONFIG_KEY);
        if ($json !== null && is_array($raw = json_decode($json, true))) {
            $v = self::validateConfig($raw);
            if ($v['config'] !== null) {
                $cfg = $v['config'];
            }
        }
        return self::$cfg = $cfg;
    }

    public static function flushCache(): void
    {
        self::$cfg = null;
    }

    /**
     * Validación estricta: cualquier valor fuera de rango o de tipo produce errores y no se guarda nada.
     *
     * @param array<mixed> $raw
     * @return array{config:?array<string,mixed>, errors:list<string>}
     */
    public static function validateConfig(array $raw): array
    {
        $errors = [];
        $int = static function (mixed $v, int $min, int $max): ?int {
            if (is_string($v) && preg_match('/^\d{1,9}$/', trim($v))) {
                $v = (int) trim($v);
            }
            return is_int($v) && $v >= $min && $v <= $max ? $v : null;
        };
        $need = static function (string $label, ?int $v, int $min, int $max) use (&$errors): int {
            if ($v === null) {
                $errors[] = "$label: entero entre $min y $max.";
            }
            return (int) $v;
        };
        $cfg = [];
        $cfg['share_pct'] = $need('Porcentaje del creador', $int($raw['share_pct'] ?? null, 0, 90), 0, 90);
        $q = $raw['share_on_quota_unlock'] ?? null;
        if (is_string($q)) {
            $q = in_array(strtolower($q), ['1', 'true', 'on', 'yes'], true) ? true : (in_array(strtolower($q), ['0', 'false', 'off', 'no', ''], true) ? false : null);
        }
        if (!is_bool($q)) {
            $errors[] = 'Reparto por cupo de membresía: verdadero o falso.';
        }
        $cfg['share_on_quota_unlock'] = $q === true;
        $cfg['min_price'] = $need('Precio mínimo', $int($raw['min_price'] ?? null, 1, 10000), 1, 10000);
        $cfg['max_price'] = $need('Precio máximo', $int($raw['max_price'] ?? null, 1, 10000), 1, 10000);
        if ($cfg['min_price'] > $cfg['max_price']) {
            $errors[] = 'El precio mínimo no puede superar al máximo.';
        }
        $cfg['max_pending'] = $need('Máximo de envíos pendientes', $int($raw['max_pending'] ?? null, 1, 50), 1, 50);
        $cfg['max_templates'] = $need('Máximo de plantillas públicas por usuario', $int($raw['max_templates'] ?? null, 1, 500), 1, 500);
        $cfg['success_uses'] = $need('Usos para plantilla exitosa', $int($raw['success_uses'] ?? null, 1, 100000), 1, 100000);

        $ms = [];
        $rawMs = $raw['milestones'] ?? [];
        if (!is_array($rawMs) || count($rawMs) > Awards::MAX_MILESTONES) {
            $errors[] = 'Como máximo ' . Awards::MAX_MILESTONES . ' hitos de creador.';
        } else {
            $prev = 0;
            foreach (array_values($rawMs) as $i => $m) {
                $c = is_array($m) ? $int($m['count'] ?? null, 1, 10000) : null;
                $coins = is_array($m) ? $int($m['coins'] ?? null, 0, Awards::MAX_COINS) : null;
                $badge = is_array($m) && is_string($m['badge'] ?? '') ? (string) ($m['badge'] ?? '') : null;
                if ($c === null || $coins === null || $badge === null || !in_array($badge, self::BADGE_CHOICES, true)) {
                    $errors[] = 'Hito de creador ' . ($i + 1) . ': plantillas 1-10000, monedas 0-' . Awards::MAX_COINS . ' e insignia válida.';
                    continue;
                }
                if ($c <= $prev) {
                    $errors[] = 'Los hitos de creador deben ser crecientes y sin repetir (revisa el hito ' . ($i + 1) . ').';
                    continue;
                }
                $prev = $c;
                $ms[] = ['count' => $c, 'coins' => $coins, 'badge' => $badge];
            }
        }
        $cfg['milestones'] = $ms;
        $cfg['month_min_templates'] = $need('Plantillas mínimas para el premio mensual', $int($raw['month_min_templates'] ?? null, 1, 1000), 1, 1000);
        $cfg['month_min_uses'] = $need('Usos mínimos en el mes', $int($raw['month_min_uses'] ?? null, 1, 1000000), 1, 1000000);
        $cfg['month_coins'] = $need('Monedas del premio mensual', $int($raw['month_coins'] ?? null, 0, Awards::MAX_COINS), 0, Awards::MAX_COINS);
        $slug = $raw['top_tier_slug'] ?? null;
        if (!is_string($slug) || ($slug !== '' && !preg_match('/^[a-z0-9_-]{1,40}$/D', $slug))) {
            $errors[] = 'Plan de la mejora temporal: identificador de plan válido (o vacío para desactivar).';
            $slug = '';
        }
        $cfg['top_tier_slug'] = $slug;
        $cfg['top_tier_days'] = $need('Días de la mejora temporal', $int($raw['top_tier_days'] ?? null, 0, 365), 0, 365);
        return $errors === [] ? ['config' => $cfg, 'errors' => []] : ['config' => null, 'errors' => $errors];
    }

    /** @param array<mixed> $raw @return list<string> errores (vacío = guardado) */
    public static function saveConfig(array $raw): array
    {
        $v = self::validateConfig($raw);
        if ($v['config'] === null) {
            return $v['errors'];
        }
        Awards::putSetting(self::CONFIG_KEY, json_encode($v['config'], JSON_THROW_ON_ERROR));
        self::flushCache();
        return [];
    }

    /** Mes ('YYYY-MM') desde el que rigen los premios de creadores (no retroactivos). */
    public static function launchMonth(): string
    {
        $v = Awards::setting(self::LAUNCH_KEY);
        if ($v === null || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) {
            $v = Awards::monthKey();
            Awards::putSetting(self::LAUNCH_KEY, $v);
        }
        return $v;
    }

    // -------------------------------------------------------------- archivos ---

    public static function dir(): string
    {
        return ROOT . '/storage/user_templates';
    }

    public static function htmlPath(string $slug): string
    {
        return self::dir() . '/' . $slug . '.html';
    }

    public static function pendingPath(string $slug): string
    {
        return self::dir() . '/' . $slug . '.new.html';
    }

    /** HTML de la plantilla de usuario (el vigente), o null. */
    public static function html(string $slug): ?string
    {
        if (preg_match(self::SLUG_RE, $slug) !== 1 || !is_file(self::htmlPath($slug))) {
            return null;
        }
        $h = file_get_contents(self::htmlPath($slug));
        return $h === false ? null : $h;
    }

    /** HTML para revisar: el reenvío pendiente si existe; si no, el vigente. */
    public static function reviewHtml(string $slug): ?string
    {
        if (preg_match(self::SLUG_RE, $slug) === 1 && is_file(self::pendingPath($slug))) {
            $h = file_get_contents(self::pendingPath($slug));
            return $h === false ? null : $h;
        }
        return self::html($slug);
    }

    /** Renderiza el documento de una página con plantilla de usuario: mismo motor, todos los datos escapados. */
    public static function render(string $slug, array $data, array $raw = []): string
    {
        $html = self::html($slug);
        if ($html === null) {
            throw new RuntimeException('Plantilla de usuario no encontrada.');
        }
        $data['days_together'] = (string) Template::daysTogether((string) ($data['start_date'] ?? ''));
        return Template::renderString($html, $data, $raw);
    }

    /** Datos de ejemplo para vistas previas (nunca datos reales). @return array<string,string> */
    public static function demoData(): array
    {
        return [
            'your_name'    => 'Tu nombre',
            'partner_name' => 'Nombre de tu pareja',
            'start_date'   => (new DateTimeImmutable('-400 days'))->format('Y-m-d'),
            'message'      => "Así se verá tu página con tus propios datos.\nCada palabra la escribes tú.",
        ];
    }

    // ----------------------------------------------------------------- envío ---

    /** image_spec para N fotos opcionales (null si 0). Validado con TemplateImages::parse. */
    public static function imageSpec(int $photos): ?string
    {
        if ($photos < 1) {
            return null;
        }
        $json = json_encode(['repeat' => ['prefix' => 'foto', 'label' => 'Foto {n}', 'min' => 0, 'max' => $photos]], JSON_THROW_ON_ERROR);
        $err = null;
        TemplateImages::parse($json, $err);
        return $err === null ? $json : null;
    }

    /**
     * Valida el archivo subido: solo .html/.htm único (los .zip no se admiten para plantillas públicas todavía),
     * mismas defensas que el HTML propio y escaneo en modo plantilla.
     *
     * @return array{html:string, errors:list<string>}
     */
    public static function inspectUpload(string $tmpPath, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) {
            return ['html' => '', 'errors' => ['Sube un único archivo .html (los .zip aún no se admiten para plantillas públicas).']];
        }
        require_once __DIR__ . '/HtmlScanner.php';
        require_once __DIR__ . '/UserHtml.php';
        $r = UserHtml::inspect($tmpPath, $origName);
        if ($r['errors'] !== []) {
            return ['html' => '', 'errors' => $r['errors']];
        }
        $errs = HtmlScanner::scanTemplate($r['html']);
        return $errs === [] ? ['html' => $r['html'], 'errors' => []] : ['html' => '', 'errors' => $errs];
    }

    /** Alias válido del usuario (users.display_name) o null. */
    public static function alias(int $userId): ?string
    {
        $st = db()->prepare('SELECT display_name FROM users WHERE id = ?');
        $st->execute([$userId]);
        $a = $st->fetchColumn();
        return is_string($a) && Ranking::validateAlias($a)['error'] === null ? $a : null;
    }

    /** @return array{pending:int, total:int} plantillas públicas del usuario (total: sin rechazadas ni retiradas). */
    public static function counts(int $userId): array
    {
        $st = db()->prepare("SELECT COALESCE(SUM(review_status = 'pending'), 0), COALESCE(SUM(review_status IN ('pending','approved')), 0)
                               FROM templates WHERE owner_user_id = ? AND kind = 'utpl'");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['pending' => (int) $r[0], 'total' => (int) $r[1]];
    }

    /**
     * Crea el envío (pending, inactivo). Toda la validación de contenido ya ocurrió; aquí se aplican los límites
     * anti-spam bajo bloqueo del usuario, se inserta la fila y se escriben los archivos; ante cualquier fallo no queda nada.
     * NO toca el cupo de HTML propio (html_uploads).
     *
     * @param array{name:string,description:string,category:string,photos:int,price:int,quota:bool} $in
     * @param string $thumbPath WebP ya procesado (ImageStore::thumbnail) en un directorio temporal
     * @param string|null $newAlias alias (ya validado) que se guarda en users.display_name si el usuario no tiene uno válido
     * @return array{id:int, slug:string, errors:list<string>}
     */
    public static function submit(int $userId, array $in, string $html, string $thumbPath, ?string $newAlias = null): array
    {
        $cfg = self::config();
        $fail = static fn (string $m): array => ['id' => 0, 'slug' => '', 'errors' => [$m]];
        $errors = [];
        $name = trim($in['name']);
        if ($name === '' || mb_strlen($name) > 60) {
            $errors[] = 'El nombre debe tener entre 1 y 60 caracteres.';
        }
        if (mb_strlen($in['description']) > 200) {
            $errors[] = 'La descripción admite hasta 200 caracteres.';
        }
        if (!array_key_exists($in['category'], Template::CATEGORIES)) {
            $errors[] = 'Elige una categoría válida.';
        }
        if ($in['photos'] < 0 || $in['photos'] > self::MAX_PHOTOS) {
            $errors[] = 'El número de fotos debe estar entre 0 y ' . self::MAX_PHOTOS . '.';
        }
        if ($in['price'] < $cfg['min_price'] || $in['price'] > $cfg['max_price']) {
            $errors[] = 'El precio debe estar entre ' . $cfg['min_price'] . ' y ' . $cfg['max_price'] . ' monedas.';
        }
        if ($errors !== []) {
            return ['id' => 0, 'slug' => '', 'errors' => $errors];
        }
        $spec = self::imageSpec($in['photos']);
        if ($in['photos'] > 0 && $spec === null) {
            return $fail('No se pudo generar la declaración de fotos.');
        }
        $pdo = db();
        $slug = 'u-' . bin2hex(random_bytes(5));
        $thumb = bin2hex(random_bytes(8)) . '.webp';
        $written = [];
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id, display_name FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $u = $lock->fetch();
            if (!$u) {
                $pdo->rollBack();
                return $fail('Usuario inexistente.');
            }
            $alias = is_string($u['display_name']) && Ranking::validateAlias($u['display_name'])['error'] === null ? $u['display_name'] : null;
            if ($alias === null) {
                $v = Ranking::validateAlias((string) $newAlias);
                if ($newAlias === null || $v['error'] !== null) {
                    $pdo->rollBack();
                    return $fail($v['error'] ?? 'Necesitas un alias público para mostrarlo como autor.');
                }
                if (Ranking::aliasTaken($v['value'], $userId)) {
                    $pdo->rollBack();
                    return $fail('Ese alias ya está en uso. Elige otro.');
                }
                $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$v['value'], $userId]);   // no activa show_in_rankings
            }
            $c = self::counts($userId);
            if ($c['pending'] >= $cfg['max_pending']) {
                $pdo->rollBack();
                return $fail('Ya tienes ' . $cfg['max_pending'] . ' envíos pendientes de revisión. Espera a que se revisen.');
            }
            if ($c['total'] >= $cfg['max_templates']) {
                $pdo->rollBack();
                return $fail('Alcanzaste el máximo de ' . $cfg['max_templates'] . ' plantillas públicas.');
            }
            $pdo->prepare("INSERT INTO templates (slug, name, kind, category, file, description, price_usd, price_coins, thumbnail, is_premium, membership_unlocks, is_active, image_spec, owner_user_id, review_status, submitted_at)
                           VALUES (?, ?, 'utpl', ?, ?, ?, 0, ?, ?, ?, ?, 0, ?, ?, 'pending', UTC_TIMESTAMP())")
                ->execute([$slug, $name, $in['category'], $slug . '.html', trim($in['description']), $in['price'], $thumb,
                    $in['quota'] ? 1 : 0, $in['quota'] ? 1 : 0, $spec, $userId]);
            self::writeFile(self::pendingPath($slug), $html);
            $written[] = self::pendingPath($slug);
            $dest = Admin::thumbDir();
            if ((!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) || !self::moveFile($thumbPath, $dest . '/' . $thumb)) {
                throw new RuntimeException('No se pudo guardar la miniatura.');
            }
            $written[] = $dest . '/' . $thumb;
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return ['id' => $id, 'slug' => $slug, 'errors' => []];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($written as $f) {
                @unlink($f);
            }
            error_log('Creators::submit: ' . $e->getMessage());
            return $fail('No se pudo guardar tu plantilla. Inténtalo de nuevo en unos minutos.');
        }
    }

    /**
     * Reenvío con archivo nuevo (rejected/withdrawn -> pending). El HTML nuevo queda como .new.html hasta la aprobación.
     *
     * @return list<string> errores
     */
    public static function resubmit(int $userId, int $templateId, string $html): array
    {
        $cfg = self::config();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
            $st = $pdo->prepare("SELECT id, slug, review_status FROM templates WHERE id = ? AND owner_user_id = ? AND kind = 'utpl' FOR UPDATE");
            $st->execute([$templateId, $userId]);
            $t = $st->fetch();
            if (!$t || !in_array($t['review_status'], ['rejected', 'withdrawn'], true)) {
                $pdo->rollBack();
                return ['Solo puedes reenviar plantillas rechazadas o retiradas.'];
            }
            if (self::counts($userId)['pending'] >= $cfg['max_pending']) {
                $pdo->rollBack();
                return ['Ya tienes ' . $cfg['max_pending'] . ' envíos pendientes de revisión.'];
            }
            self::writeFile(self::pendingPath((string) $t['slug']), $html);
            $pdo->prepare("UPDATE templates SET review_status = 'pending', is_active = 0, review_note = NULL, submitted_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$templateId]);
            $pdo->commit();
            return [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Creators::resubmit: ' . $e->getMessage());
            return ['No se pudo reenviar tu plantilla. Inténtalo de nuevo.'];
        }
    }

    private static function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de plantillas.');
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir la plantilla.');
        }
        @chmod($path, 0644);
    }

    private static function moveFile(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (@copy($from, $to)) {
            @unlink($from);
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------- revisión ---

    /**
     * Aprueba un envío pendiente. Ajustes opcionales del admin: precio, categoría y casilla de cupo.
     * Fija credit_alias (foto instantánea del alias), activa la plantilla, promueve el reenvío y evalúa hitos.
     *
     * @param array{price?:int, category?:string, quota?:bool} $adj
     * @return list<string> errores
     */
    public static function approve(int $adminId, int $templateId, array $adj = []): array
    {
        $cfg = self::config();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, slug, owner_user_id, review_status, price_coins, category, membership_unlocks FROM templates WHERE id = ? AND kind = 'utpl' FOR UPDATE");
            $st->execute([$templateId]);
            $t = $st->fetch();
            if (!$t || $t['review_status'] !== 'pending' || $t['owner_user_id'] === null) {
                $pdo->rollBack();
                return ['Solo se pueden aprobar envíos pendientes con autor.'];
            }
            $ownerId = (int) $t['owner_user_id'];
            $lock = $pdo->prepare('SELECT is_suspended, display_name FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$ownerId]);
            $o = $lock->fetch();
            if (!$o || (int) $o['is_suspended'] === 1) {
                $pdo->rollBack();
                return ['El autor no existe o está suspendido.'];
            }
            $alias = is_string($o['display_name']) && Ranking::validateAlias($o['display_name'])['error'] === null ? $o['display_name'] : null;
            if ($alias === null) {
                $pdo->rollBack();
                return ['El autor no tiene un alias público válido.'];
            }
            $price = (int) ($adj['price'] ?? $t['price_coins']);
            if ($price < $cfg['min_price'] || $price > $cfg['max_price']) {
                $pdo->rollBack();
                return ['El precio debe estar entre ' . $cfg['min_price'] . ' y ' . $cfg['max_price'] . ' monedas.'];
            }
            $cat = (string) ($adj['category'] ?? $t['category']);
            if (!array_key_exists($cat, Template::CATEGORIES)) {
                $pdo->rollBack();
                return ['Categoría inválida.'];
            }
            $quota = array_key_exists('quota', $adj) ? ($adj['quota'] ? 1 : 0) : (int) $t['membership_unlocks'];
            $slug = (string) $t['slug'];
            if (is_file(self::pendingPath($slug))) {
                if (!@rename(self::pendingPath($slug), self::htmlPath($slug))) {
                    throw new RuntimeException('No se pudo publicar el archivo de la plantilla.');
                }
            } elseif (!is_file(self::htmlPath($slug))) {
                $pdo->rollBack();
                return ['Falta el archivo de la plantilla.'];
            }
            $pdo->prepare("UPDATE templates SET review_status = 'approved', is_active = 1, price_coins = ?, category = ?, is_premium = ?, membership_unlocks = ?,
                                  credit_alias = ?, review_note = NULL, reviewed_at = UTC_TIMESTAMP(), reviewed_by = ? WHERE id = ?")
                ->execute([$price, $cat, $quota, $quota, $alias, $adminId, $templateId]);
            CreatorAwards::evaluateMilestones($pdo, $ownerId);
            $pdo->commit();
            return [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Rechaza un envío pendiente (nota obligatoria, ≤255). @return list<string> errores */
    public static function reject(int $adminId, int $templateId, string $note): array
    {
        $note = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $note));
        if ($note === '' || mb_strlen($note) > 255) {
            return ['La nota de rechazo es obligatoria (hasta 255 caracteres).'];
        }
        $st = db()->prepare("UPDATE templates SET review_status = 'rejected', is_active = 0, review_note = ?, reviewed_at = UTC_TIMESTAMP(), reviewed_by = ?
                              WHERE id = ? AND kind = 'utpl' AND review_status = 'pending'");
        $st->execute([$note, $adminId, $templateId]);
        if ($st->rowCount() !== 1) {
            return ['Solo se pueden rechazar envíos pendientes.'];
        }
        $slug = self::slugOf($templateId);
        if ($slug !== null) {
            @unlink(self::pendingPath($slug));
        }
        return [];
    }

    /**
     * Retira del catálogo (approved/pending -> withdrawn). Las páginas ya creadas siguen vivas.
     * Con $ownerId solo actúa sobre plantillas de ese usuario (retirada por el propio creador).
     */
    public static function withdraw(int $templateId, ?int $ownerId = null, ?int $adminId = null): bool
    {
        $sql = "UPDATE templates SET review_status = 'withdrawn', is_active = 0, reviewed_at = UTC_TIMESTAMP()" . ($adminId !== null ? ', reviewed_by = ?' : '')
             . " WHERE id = ? AND kind = 'utpl' AND review_status IN ('approved','pending')" . ($ownerId !== null ? ' AND owner_user_id = ?' : '');
        $params = array_merge($adminId !== null ? [$adminId] : [], [$templateId], $ownerId !== null ? [$ownerId] : []);
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount() === 1;
    }

    private static function slugOf(int $templateId): ?string
    {
        $st = db()->prepare('SELECT slug FROM templates WHERE id = ?');
        $st->execute([$templateId]);
        $s = $st->fetchColumn();
        return is_string($s) ? $s : null;
    }

    // ------------------------------------------------------------- consultas ---

    /**
     * Contexto de venta de una plantilla de usuario (para Sites y las ganancias). null si no es 'utpl'.
     *
     * @return array{id:int, owner:?int, status:string, active:bool, price:int}|null
     */
    public static function saleContext(PDO $pdo, int $templateId): ?array
    {
        $st = $pdo->prepare("SELECT id, owner_user_id, review_status, is_active, price_coins FROM templates WHERE id = ? AND kind = 'utpl'");
        $st->execute([$templateId]);
        $r = $st->fetch();
        return $r ? ['id' => (int) $r['id'], 'owner' => $r['owner_user_id'] === null ? null : (int) $r['owner_user_id'],
                     'status' => (string) $r['review_status'], 'active' => (int) $r['is_active'] === 1, 'price' => (int) $r['price_coins']] : null;
    }

    /** @return list<array<string,mixed>> plantillas del creador con usos (de otros) y ganancias */
    public static function mine(int $userId): array
    {
        $st = db()->prepare(
            "SELECT t.id, t.slug, t.name, t.thumbnail, t.category, t.review_status, t.review_note, t.price_coins, t.membership_unlocks, t.submitted_at,
                    (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id AND s.user_id <> t.owner_user_id) AS uses,
                    (SELECT COALESCE(SUM(e.share_coins), 0) FROM template_earnings e WHERE e.template_id = t.id AND e.status = 'pending') AS pending_coins,
                    (SELECT COALESCE(SUM(e.share_coins), 0) FROM template_earnings e WHERE e.template_id = t.id AND e.status = 'paid') AS paid_coins
               FROM templates t WHERE t.owner_user_id = ? AND t.kind = 'utpl' ORDER BY t.id DESC LIMIT 100"
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> envíos por estado para el panel de administración (con correo del autor: SOLO admin) */
    public static function queue(string $status, int $limit = 100): array
    {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'withdrawn'], true)) {
            return [];
        }
        $st = db()->prepare(
            "SELECT t.id, t.slug, t.name, t.description, t.thumbnail, t.category, t.review_status, t.review_note, t.price_coins, t.is_premium, t.membership_unlocks,
                    t.image_spec, t.credit_alias, t.submitted_at, t.reviewed_at, u.id AS owner_id, u.email AS owner_email, u.display_name AS owner_alias,
                    (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id AND s.user_id <> t.owner_user_id) AS uses
               FROM templates t LEFT JOIN users u ON u.id = t.owner_user_id
              WHERE t.kind = 'utpl' AND t.review_status = ? ORDER BY t.submitted_at DESC, t.id DESC LIMIT " . max(1, min(200, $limit))
        );
        $st->execute([$status]);
        return $st->fetchAll();
    }

    public static function pendingCount(): int
    {
        return (int) db()->query("SELECT COUNT(*) FROM templates WHERE kind = 'utpl' AND review_status = 'pending'")->fetchColumn();
    }

    /**
     * Colaboradores destacados: autores con al menos una plantilla EXITOSA (≥ success_uses usos por OTROS usuarios).
     * Solo alias de autoría, nunca correos. Orden: plantillas exitosas, usos totales, id.
     *
     * @return list<array{user_id:int, alias:string, templates:int, successful:int, uses:int, badges:list<string>}>
     */
    public static function collaborators(int $limit = 20): array
    {
        $n = (int) self::config()['success_uses'];
        $st = db()->prepare(
            "SELECT x.uid, MAX(x.alias) AS alias, COUNT(*) AS templates, SUM(x.uses >= ?) AS successful, SUM(x.uses) AS uses
               FROM (SELECT t.owner_user_id AS uid, t.credit_alias AS alias,
                            (SELECT COUNT(*) FROM user_sites s WHERE s.template_id = t.id AND s.user_id <> t.owner_user_id) AS uses
                       FROM templates t JOIN users u ON u.id = t.owner_user_id AND u.is_suspended = 0
                      WHERE t.kind = 'utpl' AND t.review_status = 'approved' AND t.is_active = 1 AND t.credit_alias IS NOT NULL) x
              GROUP BY x.uid HAVING successful >= 1
              ORDER BY successful DESC, uses DESC, x.uid ASC LIMIT " . max(1, min(50, $limit))
        );
        $st->execute([$n]);
        $rows = $st->fetchAll();
        $badges = Awards::badgeKeysFor(array_map(static fn (array $r): int => (int) $r['uid'], $rows));
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['uid'], 'alias' => (string) $r['alias'], 'templates' => (int) $r['templates'],
            'successful' => (int) $r['successful'], 'uses' => (int) $r['uses'], 'badges' => $badges[(int) $r['uid']] ?? [],
        ], $rows);
    }
}
