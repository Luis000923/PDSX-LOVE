<?php
declare(strict_types=1);

/**
 * HTML propio de los usuarios: validación de la subida (.html o .zip), almacenamiento y lectura.
 *
 * - El documento vive FUERA del webroot: storage/user_html/{slug}/index.html (solo lo sirve public/frame.php).
 * - Los recursos de un .zip (css, js, imágenes, fuentes) van a public/uploads/sites/{slug}/a/ con nombres saneados;
 *   Apache no ejecuta ni sirve html/php allí y les añade nosniff + CSP `sandbox`.
 */
final class UserHtml
{
    public const MAX_UPLOAD_HTML = 524288;        // 512 KB (mismo tope que HtmlScanner)
    public const MAX_ZIP_BYTES = 5242880;         // 5 MB comprimido
    public const MAX_UNZIPPED_BYTES = 8388608;    // 8 MB descomprimido
    public const MAX_ENTRIES = 60;
    private const MAX_TEXT_FILE = 524288;         // js/css/svg/txt
    private const MAX_BINARY_FILE = 3145728;      // imágenes y fuentes
    private const MAX_PIXELS = 25_000_000;
    private const MAX_RATIO = 200;                // descomprimido/comprimido por entrada (zip-bomb)

    private const EXT_TEXT = ['css', 'js', 'svg', 'txt'];
    private const EXT_IMAGE = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    private const EXT_FONT = ['woff', 'woff2'];
    private const DANGEROUS = '/\.(?:php\d?|phtml|phar|pl|py|cgi|sh|html?|xhtml|shtml|asp|aspx|jsp|htaccess|exe|dll|bat|cmd)(?:\.|$)/i';

    /**
     * Valida un archivo subido y devuelve el documento y los recursos listos para guardar.
     *
     * @return array{errors:list<string>, html:string, assets:array<string,string>, sha256:string, bytes:int}
     */
    public static function inspect(string $tmpPath, string $origName): array
    {
        $fail = static fn (array $e): array => ['errors' => $e, 'html' => '', 'assets' => [], 'sha256' => '', 'bytes' => 0];
        $size = @filesize($tmpPath);
        if ($size === false || $size < 1) {
            return $fail(['El archivo está vacío.']);
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm', 'zip'], true)) {
            return $fail(['Solo se aceptan archivos .html, .htm o .zip.']);
        }
        $limit = $ext === 'zip' ? self::MAX_ZIP_BYTES : self::MAX_UPLOAD_HTML;
        if ($size > $limit) {
            return $fail(['El archivo pesa demasiado (máximo ' . ($ext === 'zip' ? '5 MB' : '512 KB') . ').']);
        }
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $fi->file($tmpPath);
        $head = (string) file_get_contents($tmpPath, false, null, 0, 4);
        $isZip = str_starts_with($head, "PK\x03\x04") && in_array($mime, ['application/zip', 'application/x-zip-compressed'], true);

        if ($ext === 'zip') {
            if (!$isZip) {
                return $fail(['El archivo no es un .zip válido.']);
            }
            [$res, $errors] = self::readZip($tmpPath);
            if ($errors || $res === null) {
                return $fail($errors ?: ['El .zip no es válido.']);
            }
            return ['errors' => [], 'html' => $res['html'], 'assets' => $res['assets'],
                    'sha256' => (string) hash_file('sha256', $tmpPath), 'bytes' => (int) $size];
        }

        if ($isZip || !in_array($mime, ['text/html', 'text/plain', 'application/xhtml+xml'], true)) {
            return $fail(['El contenido no es HTML (o no coincide con la extensión).']);
        }
        $html = (string) file_get_contents($tmpPath);
        $errors = self::validateHtmlDoc($html, 'index.html');
        return $errors
            ? $fail($errors)
            : ['errors' => [], 'html' => $html, 'assets' => [], 'sha256' => hash('sha256', $html), 'bytes' => strlen($html)];
    }

    /** @return list<string> */
    private static function validateHtmlDoc(string $html, string $name): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            return ['El HTML debe estar codificado en UTF-8.'];
        }
        if (preg_match('/<\s*(?:html|body|head|!doctype|div|section|main|h[1-6]|p|span|a|img|script|style)\b/i', $html) !== 1) {
            return ['El archivo no parece un documento HTML.'];
        }
        return HtmlScanner::scan($html, $name);
    }

    // ------------------------------------------------------------------- ZIP ---

    /**
     * @return array{0:?array{html:string,assets:array<string,string>},1:list<string>}
     */
    public static function readZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            return [null, ['El servidor no tiene la extensión zip de PHP.']];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return [null, ['El archivo no es un .zip válido.']];
        }
        try {
            return self::readEntries($zip);
        } finally {
            $zip->close();
        }
    }

    /** @return array{0:?array{html:string,assets:array<string,string>},1:list<string>} */
    private static function readEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            return [null, ['El .zip debe tener entre 1 y ' . self::MAX_ENTRIES . ' entradas.']];
        }
        // 1) Primera pasada solo con metadatos: rutas, enlaces, extensiones y tamaños declarados. No se lee nada aún.
        $entries = [];   // rel => [index, declaredSize]
        $errors = [];
        $declared = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                return [null, ['El .zip está dañado.']];
            }
            $name = (string) $st['name'];
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;   // basura de macOS: se ignora (no se guarda)
            }
            $isDir = str_ends_with($name, '/');
            $rel = PhpTemplate::safeEntryPath($name);
            if ($rel === null) {
                $errors[] = 'Ruta no permitida en el .zip (absoluta, con "..", oculta, con caracteres raros o bytes nulos).';
                continue;
            }
            $opsys = 0;
            $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $errors[] = 'El .zip contiene enlaces simbólicos (no permitidos).';
                continue;
            }
            if ($isDir) {
                continue;
            }
            $rel = strtolower($rel);
            if (isset($entries[$rel])) {
                return [null, ['Hay archivos con el mismo nombre (sin distinguir mayúsculas).']];
            }
            $entries[$rel] = [$i, (int) $st['size'], (int) $st['comp_size']];
            $declared += (int) $st['size'];
        }
        if ($errors) {
            return [null, array_values(array_unique($errors))];
        }
        if ($entries === []) {
            return [null, ['El .zip no contiene archivos.']];
        }
        if ($declared > self::MAX_UNZIPPED_BYTES) {
            return [null, ['El .zip descomprimido supera los 8 MB.']];
        }

        // Carpeta contenedora única (index.html dentro de "mi-pagina/"): se quita ese prefijo.
        if (!isset($entries['index.html'])) {
            $tops = array_unique(array_map(static fn (string $r): string => explode('/', $r)[0], array_keys($entries)));
            if (count($tops) === 1 && isset($entries[$tops[0] . '/index.html'])) {
                $strip = $tops[0] . '/';
                $moved = [];
                foreach ($entries as $r => $v) {
                    $moved[substr($r, strlen($strip))] = $v;
                }
                $entries = $moved;
            }
        }
        if (!isset($entries['index.html'])) {
            return [null, ['El .zip debe incluir un archivo index.html en la raíz (o dentro de una única carpeta).']];
        }

        // 2) Nombres finales saneados y extensiones permitidas.
        $final = [];   // relFinal => [index, size, comp, ext]
        foreach ($entries as $rel => [$idx, $size, $comp]) {
            $lower = strtolower($rel);
            $segs = explode('/', $lower);
            foreach ($segs as $s) {
                if (preg_match('/^[a-z0-9][a-z0-9._-]{0,60}$/', $s) !== 1) {
                    $errors[] = 'Nombre de archivo no permitido: usa minúsculas, números, punto, guion o guion bajo (máx. 60 caracteres).';
                }
            }
            $base = end($segs);
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if ($lower !== 'index.html' && preg_match(self::DANGEROUS, $base) === 1) {
                $errors[] = 'Tipo de archivo no permitido (extensión peligrosa u HTML adicional).';
                continue;
            }
            if ($lower === 'index.html') {
                $ext = 'html';
            } elseif (!in_array($ext, self::EXT_TEXT, true) && !isset(self::EXT_IMAGE[$ext]) && !in_array($ext, self::EXT_FONT, true)) {
                $errors[] = 'Tipo de archivo no permitido en el .zip (solo html, css, js, png, jpg, webp, gif, svg, woff, woff2, txt).';
                continue;
            }
            $cap = $ext === 'html' || in_array($ext, self::EXT_TEXT, true) ? self::MAX_TEXT_FILE : self::MAX_BINARY_FILE;
            if ($size > $cap) {
                $errors[] = 'Un archivo del .zip es demasiado grande (texto 512 KB, imágenes y fuentes 3 MB).';
                continue;
            }
            if ($size > 1048576 && $comp > 0 && intdiv($size, $comp) > self::MAX_RATIO) {
                $errors[] = 'El .zip tiene una relación de compresión sospechosa.';
                continue;
            }
            if (isset($final[$lower])) {
                $errors[] = 'Hay archivos con el mismo nombre (sin distinguir mayúsculas).';
                continue;
            }
            $final[$lower] = [$idx, $size, $ext];
        }
        if ($errors) {
            return [null, array_values(array_unique($errors))];
        }

        // 3) Lectura entrada a entrada con tope real (no se confía en el tamaño declarado) y escaneo.
        $html = '';
        $assets = [];
        $budget = self::MAX_UNZIPPED_BYTES;
        foreach ($final as $rel => [$idx, $size, $ext]) {
            $cap = min($budget, $ext === 'html' || in_array($ext, self::EXT_TEXT, true) ? self::MAX_TEXT_FILE : self::MAX_BINARY_FILE);
            $data = self::readEntry($zip, $idx, $cap);
            if ($data === null) {
                return [null, ['El .zip supera los límites de tamaño al descomprimir.']];
            }
            $budget -= strlen($data);
            $errs = self::validateEntry($rel, $ext, $data);
            $errors = array_merge($errors, $errs);
            if ($rel === 'index.html') {
                $html = $data;
            } else {
                $assets[$rel] = $data;
            }
        }
        if ($errors) {
            return [null, array_slice(array_values(array_unique($errors)), 0, 12)];
        }
        return [['html' => $html, 'assets' => $assets], []];
    }

    private static function readEntry(ZipArchive $zip, int $idx, int $cap): ?string
    {
        $fp = $zip->getStreamIndex($idx);
        if ($fp === false) {
            return null;
        }
        $out = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
            if (strlen($out) > $cap) {
                fclose($fp);
                return null;
            }
        }
        fclose($fp);
        return $out;
    }

    /** @return list<string> */
    private static function validateEntry(string $rel, string $ext, string $data): array
    {
        if ($ext === 'html') {
            return self::validateHtmlDoc($data, $rel);
        }
        if (in_array($ext, ['js', 'css', 'svg'], true)) {
            return mb_check_encoding($data, 'UTF-8') ? HtmlScanner::scan($data, $rel) : ["$rel: debe estar en UTF-8."];
        }
        if ($ext === 'txt') {
            return str_contains($data, "\0") || !mb_check_encoding($data, 'UTF-8') ? ["$rel: no es texto UTF-8."] : [];
        }
        if (in_array($ext, self::EXT_FONT, true)) {
            $magic = substr($data, 0, 4);
            return ($ext === 'woff' && $magic === 'wOFF') || ($ext === 'woff2' && $magic === 'wOF2') ? [] : ["$rel: no es una fuente $ext válida."];
        }
        return self::validateImage($rel, $ext, $data);
    }

    /**
     * Validación mínima de imagen (MIME real, dimensiones coherentes, tope de megapíxeles).
     * TODO(ImageStore): cuando ImageStore ofrezca validación/reencodado desde bytes, delegar aquí y guardar la versión
     * reencodada (elimina metadatos y polyglots). Ver docs/HTML_PROPIO.md.
     *
     * @return list<string>
     */
    private static function validateImage(string $rel, string $ext, string $data): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $expect = self::EXT_IMAGE[$ext] ?? '';
        $info = @getimagesizefromstring($data);
        if ($mime !== $expect || $info === false || $info['mime'] !== $expect) {
            return ["$rel: el contenido no coincide con la extensión de imagen."];
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return ["$rel: dimensiones de imagen no válidas (máximo 25 megapíxeles)."];
        }
        return [];
    }

    // ---------------------------------------------------------- almacenamiento ---

    public static function docPath(string $slug): string
    {
        return ROOT . '/storage/user_html/' . $slug . '/index.html';
    }

    public static function assetsDir(string $slug): string
    {
        return ROOT . '/public/uploads/sites/' . $slug . '/a';
    }

    /**
     * Guarda documento y recursos. Rutas ya validadas (slug propio de 6-12 [a-z0-9]; rel saneada). Lanza si falla.
     *
     * @param array<string,string> $assets
     */
    public static function store(string $slug, string $html, array $assets): void
    {
        if (preg_match('/^[a-z0-9]{6,12}$/D', $slug) !== 1) {
            throw new InvalidArgumentException('Slug inválido.');
        }
        foreach (array_keys($assets) as $rel) {   // primero se valida todo; después se escribe
            if (preg_match('#^(?:[a-z0-9][a-z0-9._-]{0,60}/)*[a-z0-9][a-z0-9._-]{0,60}$#D', (string) $rel) !== 1 || str_contains((string) $rel, '..')) {
                throw new InvalidArgumentException('Ruta de recurso inválida.');
            }
        }
        $doc = self::docPath($slug);
        self::mkdir(dirname($doc));
        if (file_put_contents($doc, $html, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el HTML.');
        }
        foreach ($assets as $rel => $bytes) {
            $dest = self::assetsDir($slug) . '/' . $rel;
            self::mkdir(dirname($dest));
            if (file_put_contents($dest, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo guardar un recurso.');
            }
            @chmod($dest, 0644);
        }
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta.');
        }
    }

    /**
     * Sustituye {{img_<clave>}}, {{img_count}} y {{#if img_x}}…{{/if}} por las fotos de la página (URLs generadas por el servidor:
     * siempre uploads/sites/{slug}/{16hex}.webp). Solo actúa si el HTML trae alguno de esos marcadores; sin ellos el documento
     * se devuelve idéntico. Usa el mismo motor que las plantillas, con los otros campos vacíos y your_name = nombre de la página.
     *
     * @param array<string,string> $images clave => URL
     */
    public static function withPhotos(string $html, array $images, string $name): string
    {
        if (preg_match('/\{\{\s*(?:img_\w+|#(?:if|unless)\s+img_\w+)\s*\}\}/', $html) !== 1) {
            return $html;
        }
        return Template::renderString($html, ['your_name' => $name, 'partner_name' => '', 'start_date' => '', 'message' => ''], ['images' => $images]);
    }

    /** Documento tal como se sirve (con <base> del servidor si hay recursos); null si no existe. */
    public static function document(string $slug, bool $hasAssets, ?array $images = null, string $name = ''): ?string
    {
        if (preg_match('/^[a-z0-9]{6,12}$/D', $slug) !== 1) {
            return null;
        }
        $path = self::docPath($slug);
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $html = file_get_contents($path);
        if ($html === false) {
            return null;
        }
        $html = self::withPhotos($html, $images ?? [], $name);
        return $hasAssets ? self::injectBase($html, url('uploads/sites/' . $slug . '/a/')) : $html;
    }

    /**
     * Cabeceras del documento aislado (frame.php, vista previa de plantillas de usuario): CSP `sandbox allow-scripts` SIN
     * allow-same-origin (origen opaco: sin cookies ni acceso al padre), sin red y sin caché compartida.
     */
    public static function sendSandboxHeaders(): void
    {
        foreach (['X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security'] as $h) {
            header_remove($h);
        }
        header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; "
            . "script-src 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'self'; "
            . "style-src 'unsafe-inline' 'self' https://fonts.googleapis.com; "
            . "font-src 'self' data: https://fonts.gstatic.com; "
            . "img-src 'self' data: https:; media-src 'self' data:; connect-src 'none'; form-action 'none'; "
            . "frame-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=utf-8');
    }

    /** Inserta <base href> (valor generado por el servidor). El escáner rechaza cualquier <base> del usuario. */
    public static function injectBase(string $html, string $baseUrl): string
    {
        $tag = '<base href="' . e($baseUrl) . '">';
        $out = preg_replace('/(<head\b[^>]*>)/i', '$1' . $tag, $html, 1, $n);
        if ($out !== null && $n === 1) {
            return $out;
        }
        $out = preg_replace('/^(\s*<!doctype[^>]*>)?/i', '$1' . $tag, $html, 1);
        return $out ?? $html;
    }
}
