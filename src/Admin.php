<?php
declare(strict_types=1);

/**
 * Lógica del panel de administración: control de acceso, ajustes globales,
 * validación de plantillas subidas y auditoría.
 *
 * Regla del módulo: nada de lo que llega en $_POST/$_FILES toca la BD ni el disco
 * sin pasar antes por un validador de esta clase, y toda consulta usa PDO preparado.
 */
final class Admin
{
    /** Tamaño máximo de un .html de plantilla. */
    public const MAX_TEMPLATE_BYTES = 262144;   // 256 KiB

    /** Tamaño máximo de una miniatura. */
    public const MAX_THUMB_BYTES = 524288;      // 512 KiB

    /** Marcadores crudos ({{{x}}}) que el servidor rellena; ningún otro se admite. */
    public const RAW_PLACEHOLDERS = ['nonce', 'ad_slot'];

    /** Orígenes externos permitidos en una plantilla (deben caber en la CSP de bootstrap.php). */
    public const ALLOWED_HOSTS = ['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];

    /** Ajustes reconocidos => valor por defecto. */
    public const SETTINGS = [
        'ads_enabled'          => '1',   // banner en las páginas públicas de pareja (cuentas gratis)
        'ads_html'             => '',    // HTML del banner; vacío = marcador "Publicidad"
        'announcement_enabled' => '0',   // aviso global en la app
        'announcement_text'    => '',
        'premium_price_usd'    => '',    // vacío = usar PREMIUM_PRICE_USD del .env (ej. 4.99)
        'referral_pct'         => '',    // % de monedas que gana quien invita en la 1.ª compra del referido; vacío = Referrals::DEFAULT_PCT
        'checkin_coins'        => '',    // monedas por check-in diario; vacío = Checkin::DEFAULT_COINS
        'alias_change_cost'    => '',    // monedas que cuesta CAMBIAR el alias público; vacío = Ranking::DEFAULT_ALIAS_CHANGE_COST
    ];

    public static function templateDir(): string
    {
        return ROOT . '/templates';
    }

    public static function thumbDir(): string
    {
        return ROOT . '/public/assets/thumbs';
    }

    // ------------------------------------------------------------ acceso ---

    /**
     * Puerta de entrada de TODA página de administración.
     * Sin sesión -> login. Con sesión pero sin is_admin = 1 -> 403 sin filtrar nada.
     */
    public static function guard(): array
    {
        $user = current_user();
        if (!$user) {
            redirect('login.php');
        }
        if (!self::isAdminInDb((int) $user['id'])) {
            self::log('denied', 'acceso a ' . ($_SERVER['REQUEST_URI'] ?? ''));
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('403 Forbidden');
        }
        header('Cache-Control: private, no-store');
        return $user;
    }

    /**
     * Rol verificado en la BD con una consulta propia: no depende de lo que haya cargado la sesión
     * ni de ningún valor del cliente. Una cuenta suspendida tampoco es administradora.
     */
    public static function isAdminInDb(int $userId): bool
    {
        $st = db()->prepare('SELECT 1 FROM users WHERE id = ? AND is_admin = 1 AND is_suspended = 0');
        $st->execute([$userId]);
        return $st->fetchColumn() !== false;
    }

    /** Registra una acción administrativa. Nunca lanza: la auditoría no debe tumbar la petición. */
    public static function log(string $action, string $detail = ''): void
    {
        try {
            db()->prepare('INSERT INTO admin_audit (user_id, action, detail, ip) VALUES (?, ?, ?, ?)')
                ->execute([$_SESSION['uid'] ?? null, $action, mb_substr($detail, 0, 500), client_ip()]);
        } catch (Throwable $e) {
            error_log('admin_audit: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------- ajustes ---

    /** @var array<string,string>|null Caché por petición; setSetting() la invalida. */
    private static ?array $settingsCache = null;

    /** @return array<string,string> Ajustes con los valores por defecto ya aplicados. */
    public static function settings(): array
    {
        if (self::$settingsCache === null) {
            $cache = self::SETTINGS;
            try {
                foreach (db()->query('SELECT `key`, `value` FROM settings')->fetchAll() as $row) {
                    if (array_key_exists($row['key'], $cache)) {
                        $cache[$row['key']] = (string) $row['value'];
                    }
                }
            } catch (Throwable $e) {
                // Los ajustes son accesorios: si la tabla falla, el sitio sigue con los valores por defecto.
                error_log('admin settings: ' . $e->getMessage());
            }
            self::$settingsCache = $cache;
        }
        return self::$settingsCache;
    }

    public static function setting(string $key): string
    {
        return self::settings()[$key] ?? '';
    }

    /** Guarda un ajuste conocido. Las claves desconocidas se ignoran en silencio. */
    public static function setSetting(string $key, string $value): void
    {
        if (!array_key_exists($key, self::SETTINGS)) {
            return;
        }
        // `key` y `value` van entre acentos graves: `key` es palabra reservada en MySQL.
        db()->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
                       ON DUPLICATE KEY UPDATE `value` = ?, updated_at = UTC_TIMESTAMP()')
            ->execute([$key, $value, $value]);
        self::$settingsCache = null;
    }

    // -------------------------------------------------------- plantillas ---

    /** Convierte un nombre libre en un slug seguro ([a-z0-9-]), o '' si no queda nada. */
    /**
     * Precio en USD escrito por el admin -> "4.99" normalizado, o null si es inválido.
     * Vacío/0 = "0.00" (sin compra suelta). Máx. $999.99 y hasta 2 decimales.
     */
    public static function parsePriceUsd(string $raw): ?string
    {
        $raw = str_replace(',', '.', trim($raw));
        if ($raw === '' || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $raw)) {
            return $raw === '' ? '0.00' : null;
        }
        return number_format((float) $raw, 2, '.', '');
    }

    public static function slugify(string $text): string
    {
        $t = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(self::deaccent($text), 'UTF-8'));
        return trim($t, '-');
    }

    private static function deaccent(string $text): string
    {
        $from = ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù','â','ê','î','ô','û','ç'];
        $to   = ['a','e','i','o','u','u','n','a','e','i','o','u','a','e','i','o','u','c'];
        return str_replace($from, $to, $text);
    }

    /**
     * Comprueba que un .html subido es una plantilla legítima y no un vector de ataque.
     * No "limpia" el HTML: lo rechaza. Una plantilla dudosa no se guarda.
     *
     * @return string[] Lista de errores; vacía = válida.
     */
    public static function validateTemplateHtml(string $html): array
    {
        $errors = [];

        if ($html === '') {
            return ['El archivo está vacío.'];
        }
        if (strlen($html) > self::MAX_TEMPLATE_BYTES) {
            $errors[] = 'El archivo supera ' . (self::MAX_TEMPLATE_BYTES / 1024) . ' KiB.';
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $errors[] = 'El archivo no está codificado en UTF-8.';
        }
        if (stripos($html, '<?') !== false || str_contains($html, '<%')) {
            $errors[] = 'Contiene etiquetas de código de servidor (<?php, <?=, <%). No se permite código ejecutable.';
        }
        if (stripos($html, '<html') === false) {
            $errors[] = 'Debe ser un documento HTML completo (falta <html>).';
        }

        // Marcadores: solo se admiten los campos declarados en Template y los crudos del servidor.
        $allowedVars = array_merge(array_keys(Template::FIELDS), ['days_together']);
        preg_match_all('/\{\{\{\s*(\w+)\s*\}\}\}|\{\{\s*(\w+)\s*\}\}/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                if (!in_array($m[1], self::RAW_PLACEHOLDERS, true)) {
                    $errors[] = "Marcador crudo no permitido: {{{{$m[1]}}}}. Solo: " . implode(', ', self::RAW_PLACEHOLDERS) . '.';
                }
            } elseif (!in_array($m[2], $allowedVars, true) && !preg_match('/^img_(count|[a-z][a-z0-9_]{0,29})$/D', $m[2])) {   // fotos: {{img_<clave>}}, {{img_count}}
                $errors[] = "Variable desconocida: {{{$m[2]}}}. Disponibles: " . implode(', ', $allowedVars) . '.';
            }
        }

        // Todo <script> debe llevar el nonce del servidor; si no, la CSP lo bloquearía en silencio.
        preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
        foreach ($scripts[0] as $tag) {
            if (!preg_match('/\bnonce\s*=\s*["\']\{\{\{\s*nonce\s*\}\}\}["\']/i', $tag)) {
                $errors[] = 'Hay un <script> sin nonce="{{{nonce}}}"; la CSP lo bloquearía.';
                break;
            }
        }

        if (preg_match('/<[^>]+\son[a-z]+\s*=/i', $html)) {
            $errors[] = 'Manejadores de evento en línea (onclick=…) no permitidos por la CSP.';
        }
        if (preg_match('/(?:href|src|action|formaction)\s*=\s*["\']?\s*(?:javascript|vbscript):/i', $html)
            || stripos($html, 'data:text/html') !== false) {
            $errors[] = 'URIs javascript:, vbscript: o data:text/html no permitidas.';
        }

        // Recursos externos: deben coincidir con la CSP del sitio.
        preg_match_all('#\b(?:src|href)\s*=\s*["\']https?://([^/"\'\s]+)#i', $html, $hosts);
        foreach (array_unique(array_map('strtolower', $hosts[1])) as $host) {
            if (!in_array($host, self::ALLOWED_HOSTS, true)) {
                $errors[] = "Recurso externo no permitido por la CSP: $host";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Valida un fragmento HTML de administrador (banner de anuncios).
     * Más estricto que las plantillas: ni scripts ni iframes ni recursos externos.
     *
     * @return string[] Lista de errores.
     */
    public static function validateAdHtml(string $html): array
    {
        $errors = [];
        if (strlen($html) > 4096) {
            $errors[] = 'El banner supera los 4 KiB.';
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $errors[] = 'El banner no está codificado en UTF-8.';
        }
        if (preg_match('/<\s*(script|iframe|object|embed|form|link|meta|style)\b/i', $html, $m)) {
            $errors[] = "Etiqueta <{$m[1]}> no permitida en el banner.";
        }
        if (stripos($html, '<?') !== false || str_contains($html, '<%')) {
            $errors[] = 'Código de servidor no permitido.';
        }
        if (preg_match('/<[^>]+\son[a-z]+\s*=/i', $html)) {
            $errors[] = 'Manejadores de evento en línea no permitidos.';
        }
        if (preg_match('/(?:href|src)\s*=\s*["\']?\s*(?:javascript|vbscript):/i', $html)) {
            $errors[] = 'URIs javascript: o vbscript: no permitidas.';
        }
        preg_match_all('#\bsrc\s*=\s*["\']https?://([^/"\'\s]+)#i', $html, $hosts);
        if ($hosts[1]) {
            $errors[] = 'Las imágenes del banner deben servirse desde el propio sitio (CSP img-src \'self\').';
        }
        return array_values(array_unique($errors));
    }

    /** Traduce un código de error de $_FILES a un mensaje, o null si la subida fue bien. */
    public static function uploadError(array $file): ?string
    {
        return match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_OK        => is_uploaded_file((string) $file['tmp_name']) ? null : 'Subida inválida.',
            UPLOAD_ERR_NO_FILE   => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande.',
            UPLOAD_ERR_PARTIAL   => 'La subida se interrumpió.',
            default              => 'Error al subir el archivo.',
        };
    }

    /** Escribe la plantilla en /templates con permisos restrictivos. */
    public static function writeTemplateFile(string $basename, string $html): void
    {
        if (!preg_match('/^[a-z0-9-]+\.html$/', $basename)) {
            throw new InvalidArgumentException('Nombre de plantilla inválido.');
        }
        $path = self::templateDir() . '/' . $basename;
        if (file_put_contents($path, $html, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir la plantilla.');
        }
        @chmod($path, 0640);
    }

    /**
     * Valida y guarda una miniatura. Devuelve [basename|null, error|null].
     * Se reencoda el tipo a partir del contenido real (getimagesize), no del nombre.
     *
     * @return array{0:?string,1:?string}
     */
    public static function storeThumbnail(array $file, string $slug): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];   // miniatura opcional
        }
        if ($err = self::uploadError($file)) {
            return [null, $err];
        }
        if ((int) $file['size'] > self::MAX_THUMB_BYTES) {
            return [null, 'La miniatura supera ' . (self::MAX_THUMB_BYTES / 1024) . ' KiB.'];
        }

        $info = @getimagesize((string) $file['tmp_name']);
        $ext  = match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        };
        if ($ext === null) {
            return [null, 'La miniatura debe ser JPG, PNG o WEBP.'];
        }

        $dir = self::thumbDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [null, 'No se pudo crear el directorio de miniaturas.'];
        }
        $name = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$name")) {
            return [null, 'No se pudo guardar la miniatura.'];
        }
        @chmod("$dir/$name", 0644);
        return [$name, null];
    }

    /** Borra una miniatura del disco validando antes su nombre. */
    public static function deleteThumbnail(?string $name): void
    {
        if ($name !== null && preg_match('/^[a-z0-9-]+\.(jpg|png|webp)$/', $name)) {
            @unlink(self::thumbDir() . '/' . $name);
        }
    }

    /** Número de páginas de pareja que dependen de una plantilla. */
    public static function siteCount(int $templateId): int
    {
        $st = db()->prepare('SELECT COUNT(*) FROM user_sites WHERE template_id = ?');
        $st->execute([$templateId]);
        return (int) $st->fetchColumn();
    }

    // ------------------------------------------------------------ promos ---

    /**
     * Busca un código de promoción utilizable ahora mismo.
     * Devuelve la fila o null (código inexistente, inactivo, caducado o agotado).
     */
    public static function findUsablePromo(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9-]{3,32}$/', $code)) {
            return null;
        }
        $st = db()->prepare(
            "SELECT * FROM promos
              WHERE code = ? AND is_active = 1
                AND (expires_at IS NULL OR expires_at >= UTC_DATE())
                AND (max_uses = 0 OR uses < max_uses)"
        );
        $st->execute([$code]);
        return $st->fetch() ?: null;
    }
}
