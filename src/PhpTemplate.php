<?php
declare(strict_types=1);

/**
 * Plantillas dinámicas en PHP: una carpeta completa (index.php + assets + includes propios)
 * subida por un administrador como .zip a /templates/php/<slug>/.
 *
 * Modelo de confianza: solo un administrador puede subirlas (Admin::guard + CSRF) y solo se
 * ejecutan si pasaron el análisis de abajo. Es una defensa en profundidad, NO un sandbox: un
 * admin malicioso sigue siendo un riesgo, así que se revisa el código antes de subirlo.
 *
 * Qué garantiza el análisis (sobre los tokens de PHP, no sobre texto, para que no lo burlen
 * comentarios ni cadenas):
 *   - Rutas del zip sin `..`, absolutas, ocultas ni enlaces simbólicos; extensiones en lista blanca
 *     (comparadas siempre en minúsculas, incluida la decisión de qué se escanea y dónde se escribe:
 *     ver isPhpPath()).
 *   - Toda llamada a función global exige estar en ALLOWED_FUNCTIONS (lista BLANCA, no negra): una
 *     función no revisada se rechaza por defecto, en vez de colar hasta que alguien la prohíba.
 *   - Sin llamadas dinámicas ($f(), $$v, ->$m(), backticks, eval) ni superglobales.
 *   - `include/require` solo con `__DIR__ . '/ruta/literal.php'` que exista dentro de la carpeta.
 *   - `new` solo para DateTime/DateTimeImmutable; sin `::`, así que no alcanza las clases de la app.
 * Al ejecutarse, la plantilla recibe únicamente variables ya ESCAPADAS y vive en el ámbito de una
 * closure: no ve las variables globales de la aplicación.
 */
final class PhpTemplate
{
    public const SLUG_RE          = '/^[a-z0-9][a-z0-9_-]{2,39}$/';
    public const MAX_FILES        = 200;
    public const MAX_FILE_BYTES   = 1048576;     // 1 MiB por archivo
    public const MAX_TOTAL_BYTES  = 8388608;     // 8 MiB descomprimido
    public const ASSET_EXT        = ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'json', 'txt'];

    /**
     * Lista BLANCA de funciones globales permitidas: solo funciones puras, sin E/S, sin red, sin proceso,
     * sin callback y sin acceso al entorno/sesión de la aplicación. Cualquier función que no esté aquí se
     * rechaza, sea cual sea su nombre — así una función nueva de PHP (o una variante de nombre no prevista,
     * como ocurrió con `array_diff_ukey` bajo el modelo anterior de lista negra) queda bloqueada por defecto
     * en vez de colarse hasta que alguien la añada a una lista de prohibidas.
     */
    private const ALLOWED_FUNCTIONS = [
        // cadenas
        'strlen', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst', 'trim', 'ltrim', 'rtrim',
        'str_repeat', 'str_pad', 'str_replace', 'str_ireplace', 'substr', 'substr_count', 'str_contains',
        'str_starts_with', 'str_ends_with', 'str_split', 'wordwrap', 'word_wrap', 'sprintf', 'vsprintf',
        'number_format', 'nl2br', 'htmlspecialchars', 'htmlentities', 'html_entity_decode', 'htmlspecialchars_decode',
        'strip_tags', 'rawurlencode', 'rawurldecode', 'urlencode', 'urldecode', 'bin2hex', 'hex2bin',
        'mb_strlen', 'mb_strtolower', 'mb_strtoupper', 'mb_substr', 'mb_str_split', 'mb_convert_case',
        'implode', 'explode', 'join', 'preg_match', 'preg_match_all', 'preg_replace', 'preg_quote', 'preg_split',
        'strcmp', 'strcasecmp', 'str_word_count', 'ctype_digit', 'ctype_alpha', 'ctype_alnum', 'ctype_space',
        'ctype_upper', 'ctype_lower',
        // arrays (sin parámetro callable)
        'count', 'sizeof', 'in_array', 'array_key_exists', 'array_keys', 'array_values', 'array_merge',
        'array_unique', 'array_slice', 'array_reverse', 'array_flip', 'array_sum', 'array_product', 'array_fill',
        'array_combine', 'array_pad', 'array_chunk', 'array_diff', 'array_intersect', 'array_diff_key',
        'array_diff_assoc', 'array_intersect_key', 'array_intersect_assoc', 'array_column', 'array_key_first',
        'array_key_last', 'range', 'end', 'reset', 'current', 'key',
        // tipos
        'is_array', 'is_string', 'is_int', 'is_integer', 'is_float', 'is_double', 'is_bool', 'is_numeric',
        'is_null', 'is_scalar', 'gettype', 'intval', 'floatval', 'strval', 'boolval',
        // matemáticas
        'abs', 'ceil', 'floor', 'round', 'intdiv', 'fmod', 'pow', 'sqrt', 'max', 'min',
        // fecha (además de `new DateTime*`, ya permitido aparte)
        'date', 'gmdate', 'time', 'checkdate',
        // JSON (decode no ejecuta nada; solo produce datos)
        'json_encode', 'json_decode',
    ];
    private const DENIED_PREFIXES = ['curl_', 'stream_', 'socket_', 'mysqli_', 'pg_', 'sqlite_', 'ftp_', 'ssh2_', 'posix_', 'pcntl_', 'proc_', 'apache_', 'opcache_', 'session_'];
    private const DENIED_VARS     = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SESSION', '$_SERVER', '$_FILES', '$_ENV', '$GLOBALS'];
    private const SAFE_CLASSES    = ['datetime', 'datetimeimmutable', 'datetimezone', 'dateinterval'];

    /** Criterio ÚNICO de "es un archivo .php" (insensible a mayúsculas): usarlo en todo punto que decida si se escanea o dónde se escribe. */
    private static function isPhpPath(string $rel): bool
    {
        return strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php';
    }

    public static function baseDir(): string
    {
        return ROOT . '/templates/php';
    }

    public static function assetsDir(): string
    {
        return ROOT . '/public/assets/tpl';
    }

    // ---------------------------------------------------------------- análisis ---

    /**
     * Analiza el código de UN archivo .php. Devuelve errores legibles (vacío = aceptable).
     *
     * @param list<string> $siblings rutas relativas de todos los archivos de la plantilla (para validar include)
     * @return list<string>
     */
    public static function scan(string $code, string $rel, array $siblings = []): array
    {
        $errors = [];
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $fail = static function (string $why, int $line) use (&$errors, $rel): void {
            $errors[] = "$rel:$line: $why";
        };
        $line = 1;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : $t;
            $txt = is_array($t) ? $t[1] : $t;
            $line = is_array($t) ? $t[2] : $line;
            $prev = $i > 0 ? $tokens[$i - 1] : null;
            $prevId = is_array($prev) ? $prev[0] : $prev;
            $next = $tokens[$i + 1] ?? null;
            $nextId = is_array($next) ? $next[0] : $next;

            if ($id === T_OPEN_TAG_WITH_ECHO || $id === T_OPEN_TAG) {
                continue;
            }
            if ($id === T_EVAL || $id === T_HALT_COMPILER || $id === T_NAMESPACE || $id === T_DOUBLE_COLON || $id === '`') {
                $fail('construcción no permitida (' . trim($txt) . ')', $line);
            } elseif ($id === '$' || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $fail('variables variables no permitidas', $line);
            } elseif ($id === T_VARIABLE && in_array($txt, self::DENIED_VARS, true)) {
                $fail("acceso a $txt no permitido", $line);
            } elseif (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $fail('nombres con namespace no permitidos', $line);
            } elseif ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
                if ($nextId !== T_STRING) {
                    $fail('acceso dinámico a miembros no permitido', $line);
                }
            } elseif ($id === T_NEW) {
                if ($nextId !== T_STRING || !in_array(strtolower((string) $next[1]), self::SAFE_CLASSES, true)) {
                    $fail('solo se permite new DateTime/DateTimeImmutable', $line);
                }
            } elseif ($id === '(') {
                // Llamada sobre un valor (no sobre un nombre): $f(...), $a['x'](...), 'system'(...), (...)(...)
                if ($prevId === T_VARIABLE || $prevId === ']' || $prevId === ')' || $prevId === T_CONSTANT_ENCAPSED_STRING) {
                    $fail('llamadas dinámicas no permitidas', $line);
                }
            } elseif ($id === T_STRING && $nextId === '(' && $prevId !== T_OBJECT_OPERATOR && $prevId !== T_FUNCTION && $prevId !== T_NEW) {
                $name = strtolower($txt);
                // Lista blanca: si no está permitida explícitamente, se rechaza (bloquea por defecto
                // cualquier función no revisada, no solo las que alguien recordó prohibir por nombre).
                $allowed = in_array($name, self::ALLOWED_FUNCTIONS, true);
                foreach (self::DENIED_PREFIXES as $p) {   // cinturón y tirantes: ni un descuido en la lista blanca cuela estas familias
                    $allowed = $allowed && !str_starts_with($name, $p);
                }
                if (!$allowed) {
                    $fail("función no permitida (no está en la lista de funciones autorizadas): $txt()", $line);
                }
            } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                // Único patrón aceptado: include __DIR__ . '/ruta/literal.php';  (o con paréntesis)
                $j = $i + 1;
                $paren = ($tokens[$j] ?? null) === '(';
                $j += $paren ? 1 : 0;
                $a = $tokens[$j] ?? null;
                $dot = $tokens[$j + 1] ?? null;
                $lit = $tokens[$j + 2] ?? null;
                $ok = is_array($a) && $a[0] === T_DIR && $dot === '.' && is_array($lit) && $lit[0] === T_CONSTANT_ENCAPSED_STRING;
                $path = $ok ? substr($lit[1], 1, -1) : '';
                $ok = $ok && preg_match('#^/[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*\.php$#', $path) === 1;
                if (!$ok) {
                    $fail("solo se permite include __DIR__ . '/ruta/literal.php'", $line);
                } else {
                    $target = ltrim(self::normalize(dirname($rel) . $path) ?? '', '/');
                    if ($target === '' || !in_array($target, $siblings, true)) {
                        $fail("el include apunta a un archivo que no está en la plantilla: $path", $line);
                    }
                }
            }
        }
        return array_values(array_unique($errors));
    }

    /** Resuelve "a/./b/../c" sin tocar el disco; null si sube por encima de la raíz. */
    private static function normalize(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    /**
     * Valida la RUTA de una entrada del zip. Devuelve la ruta normalizada o null si es peligrosa.
     * Rechaza absolutas, `..`, barras invertidas, bytes nulos, segmentos ocultos y caracteres raros.
     */
    public static function safeEntryPath(string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name)) {
            return null;
        }
        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $s) {
            if ($s === '' || $s === '.' || $s === '..' || $s[0] === '.' || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $s)) {
                return null;
            }
        }
        return implode('/', $segments);
    }

    // ------------------------------------------------------------- instalación ---

    /**
     * Valida un .zip y lo instala en templates/php/<slug>/ (assets estáticos también en public/assets/tpl/<slug>/).
     * Si la carpeta existe, la reemplaza de forma atómica. Devuelve errores (vacío = instalada).
     *
     * @return list<string>
     */
    public static function install(string $slug, string $zipPath): array
    {
        if (!preg_match(self::SLUG_RE, $slug)) {
            return ['El identificador debe tener 3-40 caracteres [a-z0-9_-].'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['El servidor no tiene la extensión zip de PHP.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['El archivo no es un .zip válido.'];
        }

        try {
            [$files, $errors] = self::readZip($zip);
            if ($errors) {
                return $errors;
            }
            $names = array_keys($files);
            foreach ($files as $rel => $bytes) {
                if (self::isPhpPath($rel)) {
                    $errors = array_merge($errors, self::scan($bytes, $rel, $names));
                }
            }
            if ($errors) {
                return $errors;
            }
            return self::writeAtomically($slug, $files);
        } finally {
            $zip->close();
        }
    }

    /**
     * Contenido de manifest.json en la RAÍZ del zip (máx. 16 KiB), o null si no existe.
     * Solo lee; la validación de `images` la hace TemplateImages.
     */
    public static function manifestFromZip(string $zipPath): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            $st = $zip->statName('manifest.json');
            if ($st === false || (int) $st['size'] > 16384) {
                return null;
            }
            $raw = $zip->getFromName('manifest.json', 16385);
            return is_string($raw) && strlen($raw) <= 16384 ? $raw : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Lee y comprueba todas las entradas. Devuelve [ruta => contenido, errores].
     *
     * @return array{0:array<string,string>,1:list<string>}
     */
    private static function readZip(ZipArchive $zip): array
    {
        $errors = [];
        $files = [];
        $total = 0;
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            return [[], ['El zip debe tener entre 1 y ' . self::MAX_FILES . ' archivos.']];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                return [[], ['Zip dañado.']];
            }
            $name = (string) $st['name'];
            if (str_ends_with($name, '/')) {
                continue;   // directorio: sus archivos traen la ruta completa
            }
            $rel = self::safeEntryPath($name);
            if ($rel === null) {
                $errors[] = 'Ruta no permitida en el zip: ' . mb_substr($name, 0, 80);
                continue;
            }
            $opsys = 0;
            $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $errors[] = "Los enlaces simbólicos no se permiten: $rel";
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if ($ext !== 'php' && !in_array($ext, self::ASSET_EXT, true)) {
                $errors[] = "Extensión no permitida: $rel";
                continue;
            }
            $size = (int) $st['size'];
            $total += $size;
            if ($size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                $errors[] = 'El zip supera el tamaño máximo permitido.';
                break;
            }
            $bytes = (string) $zip->getFromIndex($i, self::MAX_FILE_BYTES + 1);   // tope real, no el declarado
            if (strlen($bytes) !== $size) {
                $errors[] = "Tamaño inconsistente: $rel";
                continue;
            }
            $files[$rel] = $bytes;
        }
        if ($errors) {
            return [[], $errors];
        }

        // Si todo viene dentro de una única carpeta raíz (flores_amarillas/index.php), se quita.
        $tops = array_unique(array_map(static fn(string $p): string => explode('/', $p)[0], array_keys($files)));
        if (!isset($files['index.php']) && count($tops) === 1 && !isset($files[$tops[0]])) {
            $prefix = $tops[0] . '/';
            $files = array_combine(
                array_map(static fn(string $p): string => substr($p, strlen($prefix)), array_keys($files)),
                array_values($files)
            );
        }
        if (!isset($files['index.php'])) {
            return [[], ['Falta index.php en la raíz de la plantilla.']];
        }
        return [$files, []];
    }

    /**
     * @param array<string,string> $files
     * @return list<string>
     */
    private static function writeAtomically(string $slug, array $files): array
    {
        $base = self::baseDir();
        $assets = self::assetsDir();
        foreach ([$base, $assets] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                return ['No se pudo crear el directorio de plantillas.'];
            }
        }
        $tag = bin2hex(random_bytes(4));
        $stageCode = "$base/.new-$tag";
        $stageAssets = "$assets/.new-$tag";
        try {
            foreach ($files as $rel => $bytes) {
                $isPhp = self::isPhpPath($rel);
                $dest = ($isPhp ? $stageCode : $stageAssets) . '/' . $rel;
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                    throw new RuntimeException('mkdir');
                }
                if (file_put_contents($dest, $bytes, LOCK_EX) === false) {
                    throw new RuntimeException('write');
                }
                @chmod($dest, $isPhp ? 0640 : 0644);
            }
            if (!is_dir($stageCode)) {
                mkdir($stageCode, 0755, true);
            }
            if (!is_dir($stageAssets)) {
                mkdir($stageAssets, 0755, true);
            }
            foreach ([[$stageCode, "$base/$slug"], [$stageAssets, "$assets/$slug"]] as [$from, $to]) {
                $old = null;
                if (is_dir($to)) {
                    $old = "$to.old-$tag";
                    rename($to, $old);
                }
                rename($from, $to);
                if ($old !== null) {
                    self::rmTree($old);
                }
            }
        } catch (Throwable $e) {
            error_log('PhpTemplate install: ' . $e->getMessage());
            self::rmTree($stageCode);
            self::rmTree($stageAssets);
            return ['No se pudo instalar la plantilla.'];
        }
        return [];
    }

    /** Borra la carpeta de una plantilla y sus assets (valida el slug: nunca sale de su directorio base). */
    public static function remove(string $slug): void
    {
        if (preg_match(self::SLUG_RE, $slug)) {
            self::rmTree(self::baseDir() . '/' . $slug);
            self::rmTree(self::assetsDir() . '/' . $slug);
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) && !is_link($p) ? self::rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // --------------------------------------------------------------- ejecución ---

    /**
     * Ejecuta templates/php/<slug>/index.php.
     *
     * @param array<string,string> $data datos de usuario CRUDOS (aquí se escapan)
     * @param array<string,mixed> $raw  valores de confianza del servidor: nonce, ad_slot, images (clave => URL)
     */
    public static function render(string $slug, array $data, array $raw = []): string
    {
        if (!preg_match(self::SLUG_RE, $slug)) {
            throw new InvalidArgumentException('Plantilla inválida.');
        }
        // El slug ya no puede contener '/' ni '..', pero se comprueba también el destino real.
        $baseReal = realpath(self::baseDir());
        $entry = realpath(self::baseDir() . '/' . $slug . '/index.php');
        if ($baseReal === false || $entry === false || !str_starts_with($entry, $baseReal . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Plantilla no encontrada.');
        }

        $t = [];
        foreach (Template::FIELDS as $field => $_) {
            $v = htmlspecialchars((string) ($data[$field] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $t[$field] = $field === 'message' ? nl2br($v, false) : $v;
        }
        $t['days_together'] = (string) Template::daysTogether((string) ($data['start_date'] ?? ''));

        // Fotos de la página: clave => URL (ya generadas por el servidor; se revalidan y escapan aquí).
        $t['images'] = [];
        foreach (is_array($raw['images'] ?? null) ? $raw['images'] : [] as $k => $u) {
            if (is_string($k) && is_string($u) && preg_match(TemplateImages::KEY_RE, $k)) {
                $t['images'][$k] = htmlspecialchars($u, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $t['image_count'] = count($t['images']);

        $nonce  = (string) ($raw['nonce'] ?? '');
        $adSlot = (string) ($raw['ad_slot'] ?? '');
        $assets = htmlspecialchars(url('assets/tpl/' . $slug . '/'), ENT_QUOTES, 'UTF-8');   // termina en "/"

        // Ámbito cerrado: la plantilla solo ve $t, $nonce, $ad_slot y $assets (todo texto, sin closures:
        // así no hay ninguna llamada dinámica que el análisis tenga que permitir).
        $run = static function (string $__entry, array $t, string $nonce, string $ad_slot, string $assets): void {
            include $__entry;
        };

        $level = ob_get_level();
        ob_start();
        try {
            $run($entry, $t, $nonce, $adSlot, $assets);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
