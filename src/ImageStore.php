<?php
declare(strict_types=1);

/**
 * Procesa fotos subidas por usuarios de forma segura y las guarda en public/uploads/sites/{slug}/.
 *
 * Nunca se usa nada del cliente (nombre, extensión, ruta). Toda imagen se decodifica con GD y se vuelve a
 * codificar siempre (WebP, sin metadatos): así se elimina EXIF y cualquier carga polyglot.
 */
final class ImageStore
{
    public const MAX_BYTES       = 5 * 1024 * 1024;    // por archivo subido
    public const MAX_TOTAL_BYTES = 30 * 1024 * 1024;   // procesado, por página
    public const MAX_PIXELS      = 25_000_000;
    public const MAX_SIDE        = 10000;
    public const OUT_SIDE        = 1600;

    private const TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png'  => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Procesa una entrada de $_FILES. Devuelve los datos de la imagen ya procesada (en $tmpDir) o un mensaje de error.
     *
     * @param array<string,mixed> $f
     * @return array{path:string,width:int,height:int,bytes:int}|string
     */
    public static function fromUpload(array $f, string $tmpDir): array|string
    {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        $tmp = $f['tmp_name'] ?? null;
        if (!is_int($err) || !is_string($tmp)) {   // p. ej. name[]=... manipulado
            return 'Archivo inválido.';
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return 'La foto pesa más de 5 MB.';
        }
        if ($err !== UPLOAD_ERR_OK) {
            return 'No se pudo subir la foto. Inténtalo otra vez.';
        }
        if (!is_uploaded_file($tmp)) {
            return 'Archivo inválido.';
        }
        return self::process($tmp, $tmpDir);
    }

    /**
     * Valida, decodifica y recodifica una imagen ya presente en disco (sin comprobar is_uploaded_file).
     *
     * @return array{path:string,width:int,height:int,bytes:int}|string
     */
    public static function process(string $file, string $tmpDir): array|string
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false || $size < 1) {
            return 'El archivo está vacío.';
        }
        if ($size > self::MAX_BYTES) {
            return 'La foto pesa más de 5 MB.';
        }
        if (!function_exists('imagecreatefromstring')) {
            return 'El servidor no puede procesar imágenes ahora mismo.';
        }
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($file);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            return 'Solo se admiten fotos JPG, PNG o WebP.';
        }
        $info = @getimagesize($file);
        if ($info === false || $info[2] !== self::TYPES[$mime] || $info[0] < 1 || $info[1] < 1) {
            return 'El archivo no es una imagen válida.';
        }
        [$w, $h] = $info;
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) {
            return 'La imagen tiene demasiados píxeles (máx. 25 megapíxeles).';
        }
        if (!self::memoryFor($w, $h)) {
            return 'La imagen es demasiado grande para procesarla.';
        }
        $bytes = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            return 'No se pudo leer la foto.';
        }
        $img = @imagecreatefromstring($bytes);
        unset($bytes);
        if ($img === false) {
            return 'El archivo no es una imagen válida.';
        }
        try {
            $file8 = @file_get_contents($file, false, null, 0, 262144);   // cabecera para EXIF
            if ($mime === 'image/jpeg' && $file8 !== false) {
                $img = self::orient($img, self::exifOrientation($file8));
            }
            $img = self::fit($img);
            $ow = imagesx($img);
            $oh = imagesy($img);
            $name = bin2hex(random_bytes(8)) . '.webp';
            $out = rtrim($tmpDir, '/') . '/' . $name;
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $ok = function_exists('imagewebp') && @imagewebp($img, $out, 82);
            if (!$ok) {   // el formato de salida es siempre WebP (el Dockerfile instala GD con webp)
                return 'El servidor no soporta WebP.';
            }
            @chmod($out, 0644);
            $sz = (int) filesize($out);
            if ($sz < 1) {
                @unlink($out);
                return 'No se pudo procesar la foto.';
            }
            return ['path' => $out, 'width' => $ow, 'height' => $oh, 'bytes' => $sz];
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Miniatura de galería: valida y decodifica con process() (mismas defensas) y produce un WebP de $w x $h con recorte
     * centrado (cover). Devuelve la ruta del WebP final en $tmpDir, o un mensaje de error.
     *
     * @return array{path:string,width:int,height:int,bytes:int}|string
     */
    public static function thumbnail(string $file, string $tmpDir, int $w = 800, int $h = 480): array|string
    {
        $r = self::process($file, $tmpDir);
        if (is_string($r)) {
            return $r;
        }
        $img = @imagecreatefromwebp($r['path']);
        @unlink($r['path']);
        if ($img === false) {
            return 'No se pudo procesar la miniatura.';
        }
        try {
            $sw = imagesx($img);
            $sh = imagesy($img);
            $scale = max($w / $sw, $h / $sh);
            $cw = max(1, min($sw, (int) round($w / $scale)));
            $ch = max(1, min($sh, (int) round($h / $scale)));
            $out = imagecreatetruecolor($w, $h);
            if ($out === false) {
                return 'No se pudo procesar la miniatura.';
            }
            imagecopyresampled($out, $img, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $w, $h, $cw, $ch);
            $path = rtrim($tmpDir, '/') . '/' . bin2hex(random_bytes(8)) . '.webp';
            $ok = @imagewebp($out, $path, 82);
            imagedestroy($out);
            if (!$ok || (int) @filesize($path) < 1) {
                @unlink($path);
                return 'No se pudo procesar la miniatura.';
            }
            @chmod($path, 0644);
            return ['path' => $path, 'width' => $w, 'height' => $h, 'bytes' => (int) filesize($path)];
        } finally {
            imagedestroy($img);
        }
    }

    /** Mueve una foto procesada a public/uploads/sites/{slug}/ y devuelve su nombre, o null si falla. */
    public static function publish(string $processed, string $slug): ?string
    {
        if (!preg_match(TemplateImages::SLUG_RE, $slug) || !preg_match(TemplateImages::FILE_RE, basename($processed))) {
            return null;
        }
        $base = ROOT . '/public/uploads/sites';
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            return null;
        }
        $dir = $base . '/' . $slug;
        if (!is_dir($dir) && !@mkdir($dir, 0755) && !is_dir($dir)) {
            return null;
        }
        $baseReal = realpath($base);
        $dirReal = realpath($dir);
        if ($baseReal === false || $dirReal !== $baseReal . DIRECTORY_SEPARATOR . $slug || is_link($dir)) {
            return null;
        }
        $name = basename($processed);
        $dest = $dirReal . '/' . $name;
        if (!@rename($processed, $dest)) {
            if (!@copy($processed, $dest)) {
                return null;
            }
            @unlink($processed);
        }
        @chmod($dest, 0644);
        return $name;
    }

    /** Directorio temporal privado (0700) para las fotos procesadas antes de crear la página. */
    public static function makeTempDir(): ?string
    {
        $base = ROOT . '/storage/tmp_uploads';
        if (!is_dir($base) && !@mkdir($base, 0700, true)) {
            $base = sys_get_temp_dir();
        }
        $dir = rtrim($base, '/') . '/img_' . bin2hex(random_bytes(8));
        return @mkdir($dir, 0700) ? $dir : null;
    }

    public static function removeTempDir(?string $dir): void
    {
        if ($dir === null || !preg_match('#/img_[0-9a-f]{16}$#', $dir) || !is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $n) {
            if ($n !== '.' && $n !== '..' && !is_dir($dir . '/' . $n)) {
                @unlink($dir . '/' . $n);
            }
        }
        @rmdir($dir);
    }

    // -------------------------------------------------------------- internos ---

    private static function memoryFor(int $w, int $h): bool
    {
        $limit = self::iniBytes((string) ini_get('memory_limit'));
        if ($limit < 0) {
            return true;
        }
        $need = $w * $h * 5 + 8 * 1024 * 1024;   // truecolor 4 B/px + copia al escalar/rotar, holgura mínima
        $free = $limit - memory_get_usage();
        if ($need > $free && $need * 2 < 512 * 1024 * 1024) {
            @ini_set('memory_limit', (string) (memory_get_usage() + $need * 2));
            $free = self::iniBytes((string) ini_get('memory_limit')) - memory_get_usage();
        }
        return $need <= $free;
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Orientación EXIF (1-8) leyendo el segmento APP1 a mano (no depende de la extensión exif). */
    public static function exifOrientation(string $jpeg): int
    {
        $len = strlen($jpeg);
        $i = 2;
        while ($i + 4 < $len && $jpeg[$i] === "\xFF") {
            $marker = ord($jpeg[$i + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $segLen = (ord($jpeg[$i + 2]) << 8) | ord($jpeg[$i + 3]);
            if ($segLen < 2) {
                break;
            }
            if ($marker === 0xE1 && substr($jpeg, $i + 4, 6) === "Exif\0\0") {
                $t = substr($jpeg, $i + 10, $segLen - 8);
                if (strlen($t) < 12) {
                    return 1;
                }
                $le = substr($t, 0, 2) === 'II';
                $u16 = static fn(int $o): int => $le ? (ord($t[$o + 1]) << 8) | ord($t[$o]) : (ord($t[$o]) << 8) | ord($t[$o + 1]);
                $u32 = static fn(int $o): int => $le
                    ? (ord($t[$o + 3]) << 24) | (ord($t[$o + 2]) << 16) | (ord($t[$o + 1]) << 8) | ord($t[$o])
                    : (ord($t[$o]) << 24) | (ord($t[$o + 1]) << 16) | (ord($t[$o + 2]) << 8) | ord($t[$o + 3]);
                $ifd = $u32(4);
                if ($ifd < 8 || $ifd + 2 > strlen($t)) {
                    return 1;
                }
                $n = min($u16($ifd), 64);
                for ($k = 0; $k < $n; $k++) {
                    $e = $ifd + 2 + $k * 12;
                    if ($e + 12 > strlen($t)) {
                        break;
                    }
                    if ($u16($e) === 0x0112) {
                        $v = $u16($e + 8);
                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }
                return 1;
            }
            $i += 2 + $segLen;
        }
        return 1;
    }

    private static function orient(GdImage $img, int $o): GdImage
    {
        $deg = match ($o) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        if ($deg !== 0) {
            $r = imagerotate($img, (float) $deg, 0);
            if ($r !== false) {
                imagedestroy($img);
                return $r;
            }
        }
        return $img;
    }

    /** Reduce a OUT_SIDE px de lado mayor (nunca amplía). */
    private static function fit(GdImage $img): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $max = max($w, $h);
        if ($max <= self::OUT_SIDE) {
            return $img;
        }
        $s = self::OUT_SIDE / $max;
        $r = imagescale($img, max(1, (int) round($w * $s)), max(1, (int) round($h * $s)), IMG_BILINEAR_FIXED);
        if ($r === false) {
            return $img;
        }
        imagedestroy($img);
        return $r;
    }
}
