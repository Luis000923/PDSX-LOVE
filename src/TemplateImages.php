<?php
declare(strict_types=1);

/**
 * Fotos que pide una plantilla: declaración (image_spec / manifest.json), URLs seguras, carga de site_images
 * y alta de las subidas de una página nueva.
 *
 * Formato JSON de la declaración:
 *   {"max":6,"min":0,
 *    "slots":[{"key":"principal","label":"Foto principal","required":true}],
 *    "repeat":{"prefix":"foto","label":"Foto {n}","min":0,"max":12}}
 */
final class TemplateImages
{
    public const HARD_MAX   = 12;                    // fotos por página, tope global
    public const KEY_RE     = '/^[a-z][a-z0-9_]{0,29}$/D';
    public const PREFIX_RE  = '/^[a-z][a-z0-9_]{0,24}$/D';
    public const SLUG_RE    = '/^[a-z0-9]{6,12}$/D';
    public const FILE_RE    = '/^[0-9a-f]{16}\.webp$/D';
    private const LABEL_MAX = 80;

    /**
     * Valida la declaración. Devuelve la lista normalizada y expandida de campos; con JSON inválido, lista vacía
     * (el error se registra y se deja en $error).
     *
     * @return list<array{key:string,label:string,required:bool}>
     */
    public static function parse(?string $json, ?string &$error = null): array
    {
        $error = null;
        if ($json === null || trim($json) === '') {
            return [];
        }
        try {
            $spec = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($spec) || array_is_list($spec) && $spec !== []) {
                throw new InvalidArgumentException('Debe ser un objeto JSON.');
            }
            return self::expand($spec);
        } catch (Throwable $e) {
            $error = $e instanceof JsonException ? 'JSON inválido: ' . $e->getMessage() : $e->getMessage();
            error_log('TemplateImages: ' . $error);
            return [];
        }
    }

    /**
     * Extrae `images` de un manifest.json de plantilla PHP.
     *
     * @return list<array{key:string,label:string,required:bool}>
     */
    public static function fromManifest(string $manifestJson, ?string &$error = null): array
    {
        $json = self::specFromManifest($manifestJson, $error);
        return $json === null ? [] : self::parse($json, $error);
    }

    /** JSON de `images` dentro de un manifest (para guardarlo en image_spec); null si no hay o es inválido. */
    public static function specFromManifest(string $manifestJson, ?string &$error = null): ?string
    {
        $error = null;
        $m = json_decode($manifestJson, true, 8);
        if (!is_array($m)) {
            $error = 'manifest.json no es un JSON válido.';
            return null;
        }
        if (!isset($m['images'])) {
            return null;
        }
        $json = json_encode($m['images'], JSON_UNESCAPED_UNICODE);
        if ($json === false || self::validate($json) !== null) {
            $error = 'manifest.json: ' . (self::validate((string) $json) ?? 'images inválido');
            return null;
        }
        return $json;
    }

    /** Mensaje de error de una declaración, o null si es válida (vacío = sin fotos, válido). */
    public static function validate(?string $json): ?string
    {
        self::parse($json, $err);
        return $err;
    }

    /**
     * @param array<mixed> $spec
     * @return list<array{key:string,label:string,required:bool}>
     */
    private static function expand(array $spec): array
    {
        $unknown = array_diff(array_keys($spec), ['max', 'min', 'slots', 'repeat']);
        if ($unknown) {
            throw new InvalidArgumentException('Clave desconocida: ' . mb_substr((string) reset($unknown), 0, 30));
        }
        $fields = [];
        $seen = [];
        $add = static function (string $key, string $label, bool $required) use (&$fields, &$seen): void {
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Clave repetida: $key");
            }
            $seen[$key] = true;
            $fields[] = ['key' => $key, 'label' => $label, 'required' => $required];
        };

        $slots = $spec['slots'] ?? [];
        if (!is_array($slots) || !array_is_list($slots)) {
            throw new InvalidArgumentException('"slots" debe ser una lista.');
        }
        foreach ($slots as $s) {
            if (!is_array($s) || array_diff(array_keys($s), ['key', 'label', 'required']) || !is_string($s['key'] ?? null)) {
                throw new InvalidArgumentException('Cada slot necesita "key", "label" y opcionalmente "required".');
            }
            if (!preg_match(self::KEY_RE, $s['key'])) {
                throw new InvalidArgumentException('Clave de foto inválida: ' . mb_substr($s['key'], 0, 30));
            }
            $req = $s['required'] ?? false;
            if (!is_bool($req)) {
                throw new InvalidArgumentException('"required" debe ser true/false.');
            }
            $add($s['key'], self::label($s['label'] ?? $s['key']), $req);
        }

        if (isset($spec['repeat'])) {
            $r = $spec['repeat'];
            if (!is_array($r) || array_diff(array_keys($r), ['prefix', 'label', 'min', 'max']) || !is_string($r['prefix'] ?? null) || !preg_match(self::PREFIX_RE, $r['prefix'])) {
                throw new InvalidArgumentException('"repeat" necesita un "prefix" válido y solo prefix/label/min/max.');
            }
            $rmax = self::int($r['max'] ?? null, 1, self::HARD_MAX, 'repeat.max');
            $rmin = self::int($r['min'] ?? 0, 0, $rmax, 'repeat.min');
            $tpl = self::label($r['label'] ?? 'Foto {n}');
            for ($n = 1; $n <= $rmax; $n++) {
                $key = $r['prefix'] . '_' . $n;
                if (strlen($key) > 30) {
                    throw new InvalidArgumentException('Prefijo demasiado largo.');
                }
                $add($key, str_replace('{n}', (string) $n, $tpl), $n <= $rmin);
            }
        }

        if (count($fields) > self::HARD_MAX) {
            throw new InvalidArgumentException('Máximo ' . self::HARD_MAX . ' fotos por página.');
        }
        $max = isset($spec['max']) ? self::int($spec['max'], 0, self::HARD_MAX, 'max') : self::HARD_MAX;
        $min = isset($spec['min']) ? self::int($spec['min'], 0, self::HARD_MAX, 'min') : 0;
        if (count($fields) > $max) {
            throw new InvalidArgumentException("La plantilla declara más fotos ($max) de las que permite \"max\".");
        }
        if ($min > count($fields)) {
            throw new InvalidArgumentException('"min" supera el número de fotos declaradas.');
        }
        return $fields;
    }

    private static function int(mixed $v, int $lo, int $hi, string $name): int
    {
        if (!is_int($v) || $v < $lo || $v > $hi) {
            throw new InvalidArgumentException("\"$name\" debe ser un entero entre $lo y $hi.");
        }
        return $v;
    }

    private static function label(mixed $v): string
    {
        if (!is_string($v)) {
            throw new InvalidArgumentException('"label" debe ser texto.');
        }
        $v = trim((string) preg_replace('/\p{Cc}/u', '', $v));
        if ($v === '' || mb_strlen($v) > self::LABEL_MAX) {
            throw new InvalidArgumentException('"label" debe tener entre 1 y ' . self::LABEL_MAX . ' caracteres.');
        }
        return $v;
    }

    // ----------------------------------------------------------------- URLs ---

    /** URL pública de una foto, o null si slug/archivo no cumplen el formato esperado. */
    public static function urlFor(string $slug, string $file): ?string
    {
        if (!preg_match(self::SLUG_RE, $slug) || !preg_match(self::FILE_RE, $file)) {
            return null;
        }
        return url('uploads/sites/' . $slug . '/' . $file);
    }

    /**
     * Fotos de una página: clave => URL. Ignora filas con clave/archivo que no cumplan el formato
     * (defensa ante datos manipulados en site_images).
     *
     * @return array<string,string>
     */
    public static function forSite(int $siteId, string $slug): array
    {
        if ($siteId < 1 || !preg_match(self::SLUG_RE, $slug)) {
            return [];
        }
        $st = db()->prepare('SELECT slot, file FROM site_images WHERE site_id = ? ORDER BY id LIMIT ' . self::HARD_MAX);
        $st->execute([$siteId]);
        return self::urls($slug, $st->fetchAll());
    }

    /**
     * @param list<array<string,mixed>> $rows filas con slot y file
     * @return array<string,string>
     */
    public static function urls(string $slug, array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $key = (string) ($r['slot'] ?? '');
            $url = preg_match(self::KEY_RE, $key) ? self::urlFor($slug, (string) ($r['file'] ?? '')) : null;
            if ($url !== null && count($out) < self::HARD_MAX) {
                $out[$key] = $url;
            }
        }
        return $out;
    }

    // ------------------------------------------------------- subida (alta) ---

    /**
     * Valida y procesa las fotos subidas ANTES de crear la página.
     *
     * @param list<array{key:string,label:string,required:bool}> $fields
     * @param array<string,mixed> $files $_FILES
     * @return array{staged:array<string,array<string,mixed>>, errors:array<string,string>, dir:?string}
     */
    public static function stage(array $fields, array $files): array
    {
        $staged = [];
        $errors = [];
        $dir = null;
        $total = 0;
        foreach ($fields as $f) {
            $f0 = $files['img_' . $f['key']] ?? null;
            $none = !is_array($f0) || (($f0['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE);
            if ($none) {
                if ($f['required']) {
                    $errors['img_' . $f['key']] = 'Esta foto es obligatoria.';
                }
                continue;
            }
            if ($dir === null) {
                $dir = ImageStore::makeTempDir();
                if ($dir === null) {
                    return ['staged' => [], 'errors' => ['img_' . $f['key'] => 'No se pudieron procesar las fotos. Inténtalo otra vez.'], 'dir' => null];
                }
            }
            $r = ImageStore::fromUpload($f0, $dir);
            if (is_string($r)) {
                $errors['img_' . $f['key']] = $r;
                continue;
            }
            $total += $r['bytes'];
            if ($total > ImageStore::MAX_TOTAL_BYTES) {
                $errors['img_' . $f['key']] = 'Las fotos de la página superan el tamaño total permitido.';
                continue;
            }
            $staged[$f['key']] = $r;
        }
        if ($errors) {
            ImageStore::removeTempDir($dir);
            return ['staged' => [], 'errors' => $errors, 'dir' => null];
        }
        return ['staged' => $staged, 'errors' => [], 'dir' => $dir];
    }

    /**
     * Callable para Sites::create: mueve las fotos a su carpeta pública e inserta site_images.
     *
     * @param array<string,array<string,mixed>> $staged
     * @return callable(PDO,int,string,array<string,mixed>):?string
     */
    public static function committer(array $staged): callable
    {
        return static function (PDO $pdo, int $siteId, string $slug, array $user) use ($staged): ?string {
            if ($staged === []) {
                return null;
            }
            $ins = $pdo->prepare('INSERT INTO site_images (site_id, slot, file, mime, width, height, bytes) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($staged as $key => $img) {
                $file = ImageStore::publish((string) $img['path'], $slug);
                if ($file === null) {
                    return 'No se pudieron guardar las fotos. Inténtalo otra vez.';
                }
                $ins->execute([$siteId, $key, $file, 'image/webp', (int) $img['width'], (int) $img['height'], (int) $img['bytes']]);
            }
            return null;
        };
    }
}
