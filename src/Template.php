<?php
declare(strict_types=1);

/**
 * Gestor de plantillas.
 *
 * Sintaxis en /templates/*.html:
 *   {{clave}}    -> valor ESCAPADO (HTML-safe). `message` además convierte saltos de línea en <br>.
 *   {{{clave}}}  -> valor crudo, SOLO para valores generados por el servidor (nonce, ads).
 *
 * Los datos de usuario nunca se interpretan como plantilla: un solo pase de regex.
 */
require_once __DIR__ . '/ImageStore.php';
require_once __DIR__ . '/TemplateImages.php';

final class Template
{
    /** Campos editables por el usuario => longitud máxima. */
    public const FIELDS = [
        'your_name'    => 60,
        'partner_name' => 60,
        'start_date'   => 10,   // Y-m-d
        'message'      => 1000,
    ];

    /** Categorías del marketplace: clave => etiqueta. */
    public const CATEGORIES = [
        'romantico'   => 'Romántico',
        'aniversario' => 'Aniversario',
        'cumpleanos'  => 'Cumpleaños',
        'declaracion' => 'Declaración',
        'especial'    => 'Especial',
    ];

    /** Renderiza según el tipo de plantilla (fila de `templates`): html con {{campos}} o carpeta PHP aprobada. */
    public static function renderRow(array $tpl, array $data, array $raw = []): string
    {
        // Fotos de la página (si la fila trae site_id y site_slug): clave => URL generada por el servidor.
        if (!isset($raw['images']) && isset($tpl['site_id'], $tpl['site_slug'])) {
            $raw['images'] = TemplateImages::forSite((int) $tpl['site_id'], (string) $tpl['site_slug']);
        }
        return match ($tpl['kind'] ?? 'html') {
            'php'   => PhpTemplate::render((string) $tpl['slug'], $data, $raw),
            'utpl'  => Creators::render((string) $tpl['slug'], $data, $raw),
            default => self::render((string) $tpl['file'], $data, $raw),
        };
    }

    /** Mezcla datos demo con parámetros opcionales (vista previa embebida): inválido/ausente conserva el demo. */
    public static function mergeDemo(array $demo, array $input, ?DateTimeImmutable $today = null): array
    {
        foreach (self::FIELDS as $field => $max) {
            $v = $input[$field] ?? null;
            if (!is_string($v)) {
                continue;
            }
            $v = trim((string) preg_replace('/[^\P{Cc}\n]/u', '', str_replace("\r\n", "\n", $v)));
            if ($v === '') {
                continue;
            }
            if ($field === 'start_date') {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                $now = $today ?? new DateTimeImmutable('today');
                if ($d === false || $d->format('Y-m-d') !== $v || $d > $now) {
                    continue;
                }
            } else {
                $v = mb_substr($v, 0, $max);
            }
            $demo[$field] = $v;
        }
        return $demo;
    }

    /** Valida y limpia la entrada del formulario. Devuelve [datos, errores]. */
    public static function sanitize(array $input): array
    {
        $data = [];
        $errors = [];
        foreach (self::FIELDS as $field => $max) {
            $v = $input[$field] ?? '';
            $v = is_string($v) ? trim(preg_replace('/[^\P{Cc}\n]/u', '', str_replace("\r\n", "\n", $v))) : '';
            if ($v === '') {
                $errors[$field] = 'Este campo es obligatorio.';
            } elseif (mb_strlen($v) > $max) {
                $errors[$field] = "Máximo $max caracteres.";
            }
            $data[$field] = $v;
        }

        if (!isset($errors['start_date'])) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data['start_date']);
            if (!$d || $d->format('Y-m-d') !== $data['start_date']) {
                $errors['start_date'] = 'Fecha inválida.';
            } elseif ($d > new DateTimeImmutable('today')) {
                $errors['start_date'] = 'La fecha no puede ser futura.';
            }
        }
        return [$data, $errors];
    }

    /** Días transcurridos desde start_date (0 si es inválida). */
    public static function daysTogether(string $startDate): int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        return $d ? max(0, (int) $d->diff(new DateTimeImmutable('today'))->days) : 0;
    }

    /**
     * @param string $file  basename del archivo (viene de la BD, se revalida)
     * @param array  $data  datos de usuario (se escapan)
     * @param array  $raw   valores de confianza del servidor (no se escapan)
     */
    public static function render(string $file, array $data, array $raw = []): string
    {
        if (!preg_match('/^[a-z0-9-]+\.html$/', $file)) {
            throw new InvalidArgumentException('Nombre de plantilla inválido.');
        }
        $path = ROOT . '/templates/' . $file;
        $html = is_file($path) ? (string) file_get_contents($path) : throw new RuntimeException('Plantilla no encontrada.');
        return self::renderString($html, $data, $raw);
    }

    /**
     * Mismo motor que render() sobre un HTML ya leído (plantillas de usuario 'utpl'): un solo pase, valores escapados.
     *
     * @param array $data datos de la página (se escapan)
     * @param array $raw  valores de confianza del servidor (imágenes, nonce...)
     */
    public static function renderString(string $html, array $data, array $raw = []): string
    {
        $data['days_together'] = (string) self::daysTogether((string) ($data['start_date'] ?? ''));

        // Fotos: {{img_<clave>}} (URL o vacío) y {{img_count}}. Solo URLs del servidor; nada de lo que venga en
        // $data con prefijo img_ se respeta.
        foreach (array_keys($data) as $k) {
            if (str_starts_with((string) $k, 'img_')) {
                unset($data[$k]);
            }
        }
        $n = 0;
        foreach (is_array($raw['images'] ?? null) ? $raw['images'] : [] as $k => $u) {
            if (is_string($k) && is_string($u) && preg_match(TemplateImages::KEY_RE, $k)) {
                $data['img_' . $k] = $u;
                $n++;
            }
        }
        $data['img_count'] = (string) $n;

        // Condicionales de fotos {{#if img_x}}..{{/if}} y {{#unless img_x}}..{{/unless}}: un solo pase sobre el
        // texto de la plantilla (confianza del servidor), sin anidar y sin evaluar nada.
        $html = (string) preg_replace_callback(
            '/\{\{#(if|unless)\s+(img_\w+)\s*\}\}(.*?)\{\{\/\1\}\}/s',
            static fn(array $m): string => (($data[$m[2]] ?? '') !== '' && ($data[$m[2]] ?? '0') !== '0') === ($m[1] === 'if') ? $m[3] : '',
            $html
        );

        return (string) preg_replace_callback(
            '/\{\{\{\s*(\w+)\s*\}\}\}|\{\{\s*(\w+)\s*\}\}/',
            static function (array $m) use ($data, $raw): string {
                if ($m[1] !== '') {
                    return (string) ($raw[$m[1]] ?? '');
                }
                $key = $m[2];
                $val = htmlspecialchars((string) ($data[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $key === 'message' ? nl2br($val, false) : $val;
            },
            $html
        );
    }
}
