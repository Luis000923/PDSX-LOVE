<?php
declare(strict_types=1);

/**
 * Escáner de HTML/CSS/JS/SVG subido por los usuarios ("HTML propio").
 *
 * Es defensa en profundidad: la barrera real es el aislamiento (iframe sandbox sin allow-same-origin + CSP
 * `sandbox` en la cabecera de frame.php). Aquí se rechazan patrones peligrosos o abusivos ANTES de guardar nada.
 * Los mensajes son texto plano en español y nunca reflejan contenido del archivo (solo etiquetas fijas y el
 * nombre de archivo saneado); aun así, quien los muestre debe escaparlos con e().
 */
final class HtmlScanner
{
    public const MAX_HTML_BYTES = 524288;   // 512 KB

    /** Hosts permitidos para scripts/hojas de estilo/fuentes externas (coinciden con la CSP de frame.php). */
    public const ALLOWED_HOSTS = ['cdn.tailwindcss.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];
    private const SCRIPT_HOSTS = ['cdn.tailwindcss.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com'];

    private const MAX_ERRORS = 8;

    private const BAD_TAGS = ['iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'base', 'isindex', 'portal', 'noembed', 'noframes'];

    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'background', 'ping', 'codebase', 'cite', 'longdesc', 'manifest', 'srcset'];

    /** Patrones de JavaScript rechazados: etiqueta legible => regex (sensibles a mayúsculas: JS lo es). */
    private const JS_RULES = [
        'eval()'                              => '/(?<![\w$])eval\b/',
        'new Function / Function()'           => '/(?<![\w$])Function\s*\(|new\s+Function\b|Function\.prototype/',
        'setTimeout/setInterval con texto'    => '/(?<![\w$])set(?:Timeout|Interval)\s*\(\s*[\'"`]/',
        'document.write'                      => '/document\.write/',
        'document.cookie'                     => '/\.cookie\b|[\'"`]cookie[\'"`]/',
        'almacenamiento del navegador'        => '/(?<![\w$])(?:localStorage|sessionStorage|indexedDB|openDatabase)\b/',
        'peticiones de red (fetch/XHR)'       => '/XMLHttpRequest|(?<![\w$])fetch\s*\(|[\'"`]fetch[\'"`]|sendBeacon/',
        'WebSocket/EventSource'               => '/WebSocket|EventSource|RTCPeerConnection/',
        'workers / importScripts / WebAssembly' => '/importScripts|new\s+Worker\b|SharedWorker|(?<![\w$])Worker\s*\(|WebAssembly|serviceWorker/',
        'import dinámico'                     => '/(?<![\w$])import\s*[(\'"{*]/',
        'window.open'                         => '/window\.open|(?<![\w$.])open\s*\(/',
        'location'                            => '/(?<![\w$])location\b|[\'"`]location[\'"`]/',
        'acceso a la ventana superior'        => '/(?<![\w$.])(?:top|parent|opener|frames)\s*[.\[]|window\.(?:top|parent|opener|frames)|\bopener\b|globalThis|(?<![\w$])(?:window|self)\s*\[|(?<![\w$.])top\s*=(?!=)/',
        'postMessage'                         => '/postMessage/',
        'APIs del dispositivo'                => '/navigator\.(?:clipboard|geolocation|mediaDevices|serviceWorker)|getUserMedia|(?<![\w$])Notification\b|\.clipboard\b/',
        'constructor.constructor'             => '/\bconstructor\b|__proto__/',
        'código ofuscado (atob/fromCharCode)' => '/(?<![\w$])(?:atob|unescape)\s*\(|fromCharCode|fromCodePoint/',
        'minería de criptomonedas'            => '/coinhive|coin-hive|cryptonight|webmine|deepminer|cryptoloot|stratum|minero/i',
    ];

    /** @return list<string> errores legibles; vacío = aceptable */
    public static function scan(string $content, string $filename): array
    {
        $shown = self::safeName($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (strlen($content) > self::MAX_HTML_BYTES) {
            return ["$shown supera los 512 KB."];
        }
        $content = str_replace("\0", '', $content);
        $errs = match ($ext) {
            'js'    => self::scanJs($content),
            'css'   => self::scanCss($content),
            'svg'   => self::scanSvg($content),
            default => self::scanHtml($content),
        };
        $errs = array_values(array_unique($errs));
        $errs = array_map(static fn (string $m): string => $shown . ': ' . $m, array_slice($errs, 0, self::MAX_ERRORS));
        return $errs;
    }

    /**
     * Escaneo de una PLANTILLA reutilizable (kind 'utpl'): los marcadores {{campo}} del motor son legítimos, pero solo
     * en texto y atributos inocuos. Rechaza {{{crudo}}}, marcadores desconocidos, marcadores dentro de <script>/<style>,
     * de manejadores on*, de style y de atributos de URL (salvo {{img_*}} en src/href/poster: URL generada por el servidor),
     * exige al menos un campo de datos y pasa el escáner normal con los marcadores sustituidos por valores inertes.
     *
     * @return list<string>
     */
    public static function scanTemplate(string $html): array
    {
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return ['La plantilla supera los 512 KB.'];
        }
        $html = str_replace("\0", '', $html);
        $errs = [];
        if (str_contains($html, '{{{') || str_contains($html, '}}}')) {
            $errs[] = 'no se permiten marcadores crudos ({{{ }}}).';
        }
        $fields = array_merge(array_keys(Template::FIELDS), ['days_together']);
        $used = 0;
        preg_match_all('/\{\{(.*?)\}\}/s', $html, $m);
        foreach ($m[1] as $inner) {
            $t = trim($inner);
            if (in_array($t, $fields, true)) {
                $used++;
            } elseif (preg_match('/^#(?:if|unless)\s+img_[a-z][a-z0-9_]{0,29}$/D', $t) === 1
                || preg_match('#^/(?:if|unless)$#D', $t) === 1
                || preg_match('/^img_(?:[a-z][a-z0-9_]{0,29}|count)$/D', $t) === 1) {
                continue;
            } else {
                $errs[] = 'marcador desconocido {{' . mb_substr(preg_replace('/[^\w #\/-]/u', '?', $t) ?? '', 0, 30) . '}}.';
            }
        }
        if ($used === 0) {
            $errs[] = 'debe usar al menos un marcador de datos ({{your_name}}, {{partner_name}}, {{start_date}}, {{days_together}} o {{message}}); si no, publícala como HTML propio.';
        }
        if (preg_match_all('#<(script|style)\b[^>]*>(.*?)</\1\s*>#is', $html, $blocks) > 0) {
            foreach ($blocks[2] as $body) {
                if (str_contains($body, '{{')) {
                    $errs[] = 'los marcadores no pueden ir dentro de <script> ni <style>.';
                    break;
                }
            }
        }
        if (preg_match_all('/\s([a-z][\w:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $at, PREG_SET_ORDER) > 0) {
            foreach ($at as $a) {
                $val = ($a[2] ?? '') . ($a[3] ?? '') . ($a[4] ?? '');
                if (!str_contains($val, '{{')) {
                    continue;
                }
                $name = strtolower($a[1]);
                $imgOnly = preg_match('/^\s*\{\{\s*img_[a-z][a-z0-9_]{0,29}\s*\}\}\s*$/D', $val) === 1;
                if (in_array($name, ['src', 'href', 'poster'], true) ? !$imgOnly : (str_starts_with($name, 'on') || $name === 'style' || in_array($name, self::URL_ATTRS, true))) {
                    $errs[] = 'un marcador está en un atributo no permitido (' . preg_replace('/[^\w:-]/', '', $name) . ').';
                }
            }
        }
        // Sustitución inerte de marcadores para el escáner normal.
        $sub = (string) preg_replace('/\{\{\s*#(?:if|unless)\s+\w+\s*\}\}|\{\{\s*\/(?:if|unless)\s*\}\}/', '', $html);
        $sub = (string) preg_replace('/\{\{\s*img_\w+\s*\}\}/', 'data:image/gif;base64,R0lGODlhAQABAAAAACw=', $sub);
        $sub = (string) preg_replace('/\{\{.*?\}\}/s', 'x', $sub);
        $errs = array_merge($errs, self::scan($sub, 'plantilla.html'));
        return array_slice(array_values(array_unique($errs)), 0, self::MAX_ERRORS);
    }

    // ------------------------------------------------------------------ HTML ---

    /** @return list<string> */
    public static function scanHtml(string $html): array
    {
        $errs = [];
        $stripped = self::rx('/<!--.*?-->/s', $html, ' ');
        foreach ([$html, $stripped] as $variant) {
            if (preg_match('/<\s*\/?\s*(?:' . implode('|', self::BAD_TAGS) . ')(?=[\s\/>])/i', $variant, $m) === 1) {
                $errs[] = 'contiene una etiqueta no permitida (<' . strtolower(trim($m[0], "< \t\r\n/")) . '>).';
            }
            if (preg_match('/coinhive|coin-hive|cryptonight|webmine|deepminer|cryptoloot/i', $variant) === 1) {
                $errs[] = 'referencia a minería de criptomonedas.';
            }
            if (preg_match('/srcdoc\s*=/i', $variant) === 1) {
                $errs[] = 'el atributo srcdoc no está permitido.';
            }
            // Respaldo por si el analizador y el navegador difieren: todo bloque <script>…</script> del texto crudo.
            if (preg_match_all('#<script\b[^>]*>(.*?)</script\s*>#is', $variant, $blocks) > 0) {
                foreach ($blocks[1] as $code) {
                    $errs = array_merge($errs, self::scanJs($code));
                }
            }
        }

        $doc = self::loadHtml($html);
        if ($doc === null) {
            return array_merge($errs, ['no se pudo interpretar el HTML.']);
        }
        $hasInput = false;
        foreach ($doc->getElementsByTagName('*') as $el) {
            $tag = strtolower($el->nodeName);
            if (in_array($tag, self::BAD_TAGS, true)) {
                $errs[] = "contiene una etiqueta no permitida (<$tag>).";
            }
            if (in_array($tag, ['input', 'textarea', 'select'], true)) {
                $hasInput = true;
            }
            $errs = array_merge($errs, self::checkElement($el, $tag));
        }

        // Phishing: petición de credenciales o datos de pago junto a campos de entrada.
        if ($hasInput) {
            $xp = new DOMXPath($doc);
            $txt = '';
            foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style)]') ?: [] as $n) {
                $txt .= ' ' . $n->nodeValue;
            }
            $txt = mb_strtolower(self::rx('/\s+/u', $txt, ' '));
            if (preg_match('/iniciar sesi[oó]n|inicia sesi[oó]n|contrase[ñn]a|password|\bcvv\b|\bcvc\b|seed phrase|frase semilla|n[uú]mero de tarjeta|tarjeta de cr[eé]dito|tarjeta de d[eé]bito|clave secreta|verifica tu cuenta/u', $txt) === 1) {
                $errs[] = 'parece pedir credenciales o datos de pago (no se permite).';
            }
        }
        return $errs;
    }

    /** @return list<string> */
    private static function checkElement(DOMElement $el, string $tag): array
    {
        $errs = [];
        $attrs = [];
        foreach ($el->attributes ?? [] as $a) {
            $attrs[strtolower($a->nodeName)] = (string) $a->nodeValue;
        }

        foreach ($attrs as $name => $val) {
            if (str_starts_with($name, 'on')) {
                $errs = array_merge($errs, self::scanJs($val, true));
            } elseif ($name === 'style') {
                $errs = array_merge($errs, self::scanCss($val));
            } elseif ($name === 'srcdoc') {
                $errs[] = 'el atributo srcdoc no está permitido.';
            } elseif (in_array($name, self::URL_ATTRS, true)) {
                foreach ($name === 'srcset' ? explode(',', $val) : [$val] as $part) {
                    $u = trim($name === 'srcset' ? (explode(' ', trim($part))[0]) : $part);
                    $bad = self::badUrl($u, $tag === 'script' && $name === 'src' ? 'script' : ($tag === 'a' ? 'nav' : 'res'));
                    if ($bad !== null) {
                        $errs[] = $bad;
                    }
                }
            }
        }

        switch ($tag) {
            case 'script':
                if (isset($attrs['src'])) {
                    if (!self::allowedScriptSrc($attrs['src'])) {
                        $errs[] = 'solo se permiten scripts externos de cdn.tailwindcss.com, cdn.jsdelivr.net y cdnjs.cloudflare.com (o archivos de tu .zip).';
                    }
                }
                if (trim($el->textContent) !== '') {
                    $errs = array_merge($errs, self::scanJs($el->textContent));
                }
                break;
            case 'style':
                $errs = array_merge($errs, self::scanCss($el->textContent));
                break;
            case 'meta':
                $he = strtolower(trim($attrs['http-equiv'] ?? ''));
                if (in_array($he, ['refresh', 'set-cookie', 'content-security-policy', 'x-frame-options', 'default-style'], true)) {
                    $errs[] = "la etiqueta <meta http-equiv=\"$he\"> no está permitida.";
                }
                break;
            case 'link':
                $rel = strtolower($attrs['rel'] ?? '');
                $href = $attrs['href'] ?? '';
                $risky = preg_match('/\b(import|prefetch|preload|dns-prefetch|preconnect|modulepreload|stylesheet|prerender|manifest)\b/', $rel) === 1;
                $relative = preg_match('#^(?![a-z][a-z0-9+.-]*:|//|\\\\)#i', trim($href)) === 1;
                if ($risky && !$relative && !self::hostAllowed($href, self::ALLOWED_HOSTS)) {
                    $errs[] = 'un <link rel="' . preg_replace('/[^a-z -]/', '', $rel) . '"> apunta a un host no permitido.';
                }
                break;
            case 'input':
                if (strtolower(trim($attrs['type'] ?? '')) === 'password') {
                    $errs[] = 'no se permiten campos de contraseña.';
                }
                break;
            case 'use':
                $h = trim($attrs['href'] ?? $attrs['xlink:href'] ?? '');
                if ($h !== '' && $h[0] !== '#') {
                    $errs[] = 'un <use> de SVG apunta a un recurso externo.';
                }
                break;
            case 'foreignobject':
                $errs[] = 'SVG con <foreignObject> no está permitido.';
                break;
            case 'set':
            case 'animate':
                if (preg_match('/^(on|href|xlink:href)/i', $attrs['attributename'] ?? '') === 1) {
                    $errs[] = 'una animación SVG modifica atributos de enlace o eventos.';
                }
                break;
        }
        return $errs;
    }

    private static function loadHtml(string $html): ?DOMDocument
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Sin LIBXML_NOENT (no expande entidades) y con LIBXML_NONET (sin red). Forzar UTF-8 para no romper tildes.
        $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    // ------------------------------------------------------------------ URLs ---

    /** @param 'script'|'nav'|'res' $kind */
    private static function badUrl(string $url, string $kind): ?string
    {
        // Los navegadores ignoran tabulaciones/saltos/controles dentro del esquema: se eliminan antes de mirar.
        $u = strtolower(self::rx('/[\x00-\x20\x7f-\x9f\x{200b}-\x{200f}\x{feff}]+/u', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ''));
        if (preg_match('/^(?:javascript|vbscript|livescript|mocha|blob|filesystem|file|view-source|jar|ms-[a-z-]+):/', $u) === 1) {
            return 'contiene un enlace con esquema peligroso (javascript:, vbscript:, blob: u otro).';
        }
        if (str_starts_with($u, 'data:')) {
            if (preg_match('#^data:(?:image/(?:png|jpe?g|gif|webp|avif|bmp|x-icon)|font/(?:woff2?|ttf|otf)|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+)[;,]#', $u) !== 1) {
                return 'contiene un enlace data: que no es una imagen, fuente, audio o vídeo.';
            }
        }
        return null;
    }

    private static function allowedScriptSrc(string $src): bool
    {
        $s = trim($src);
        if ($s === '') {
            return false;
        }
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $s) === 1 || preg_match('#^[a-z][a-z0-9+.-]*:#i', $s) === 1) {
            return self::hostAllowed($s, self::SCRIPT_HOSTS) && (str_starts_with(strtolower($s), 'https://') || str_starts_with($s, '//'));
        }
        // Ruta relativa del propio ZIP: sin subir de directorio, sin barras iniciales ni caracteres raros.
        return preg_match('#^(?!/)(?!.*\.\.)[A-Za-z0-9._/-]{1,120}(?:\?[A-Za-z0-9=&._-]*)?$#', $s) === 1;
    }

    /** @param list<string> $hosts */
    private static function hostAllowed(string $url, array $hosts): bool
    {
        $u = trim($url);
        if (str_starts_with($u, '//')) {
            $u = 'https:' . $u;
        }
        $p = parse_url($u);
        if ($p === false || !isset($p['host'])) {
            return false;
        }
        return ($p['scheme'] ?? '') === 'https' && !isset($p['user']) && in_array(strtolower($p['host']), $hosts, true);
    }

    // -------------------------------------------------------------------- JS ---

    /** @return list<string> */
    public static function scanJs(string $js, bool $isHandler = false): array
    {
        if ($js === '') {
            return [];
        }
        $errs = [];
        $esc = preg_match_all('/\\\\(?:x[0-9a-fA-F]{2}|u[0-9a-fA-F]{4}|u\{[0-9a-fA-F]+\})/', $js);
        if ($esc !== false && $esc > 20) {
            $errs[] = 'el JavaScript parece ofuscado (demasiadas secuencias \\x / \\u).';
        }
        if (preg_match('#[A-Za-z0-9+/]{120,}={0,2}#', $js) === 1 || preg_match('/(?<![\w])[0-9a-fA-F]{80,}(?![\w])/', $js) === 1) {
            $errs[] = 'el JavaScript contiene cadenas base64/hex largas (posible ofuscación).';
        }

        $decoded = self::decodeEscapes($js);
        foreach ([$decoded, self::rx('#/\*.*?\*/#s', $decoded, ' ')] as $variant) {
            $v = self::rx('/\s*\.\s*/', str_replace('?.', '.', $variant), '.');
            $v = self::rx('/\s+/', $v, ' ');
            foreach (self::JS_RULES as $label => $re) {
                if (preg_match($re, $v) === 1) {
                    $errs[] = "JavaScript no permitido: $label.";
                }
            }
            if (preg_match('/(?:javascript|vbscript)\s*:/i', $v) === 1 && $isHandler) {
                $errs[] = 'JavaScript no permitido: esquema javascript:.';
            }
        }
        return array_values(array_unique($errs));
    }

    private static function decodeEscapes(string $s): string
    {
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $s) ?? $s;
        $s = preg_replace_callback('/\\\\u\{([0-9a-fA-F]{1,6})\}|\\\\u([0-9a-fA-F]{4})/', static function (array $m): string {
            $cp = (int) hexdec($m[1] !== '' ? $m[1] : $m[2]);
            return $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF) ? (string) mb_chr($cp, 'UTF-8') : '';
        }, $s) ?? $s;
        return str_replace(['\\', "\0"], '', $s);
    }

    // ------------------------------------------------------------------- CSS ---

    /** @return list<string> */
    public static function scanCss(string $css): array
    {
        $errs = [];
        // CSS admite escapes tipo \65 xpression; se decodifican y se quitan comentarios y espacios.
        $c = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn (array $m): string => (string) mb_chr(min(0x10FFFF, (int) hexdec($m[1])) ?: 63, 'UTF-8'), $css) ?? $css;
        $c = str_replace(['\\', "\0"], '', $c);
        foreach ([$c, self::rx('#/\*.*?\*/#s', $c, '')] as $v) {
            $v = strtolower(self::rx('/\s+/', $v, ' '));
            if (preg_match('/expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:/', $v) === 1) {
                $errs[] = 'CSS no permitido (expression, behavior, -moz-binding o url(javascript:)).';
            }
            if (preg_match('/url\(\s*[\'"]?\s*(?:data:(?!image\/|font\/|application\/font|application\/x-font))/', $v) === 1) {
                $errs[] = 'CSS no permitido (url(data:) que no es imagen ni fuente).';
            }
            if (preg_match_all('/@import\s*(?:url\(\s*)?[\'"]?\s*([^\'")\s;]+)/', $v, $mm) > 0) {
                foreach ($mm[1] as $target) {
                    if (preg_match('#^(?:https?:)?//#', $target) === 1 && !self::hostAllowed($target, self::ALLOWED_HOSTS)) {
                        $errs[] = 'CSS no permitido: @import de un host externo no permitido.';
                    } elseif (preg_match('#^(?!https?:|//)[a-z0-9._/-]+$#', $target) !== 1 && preg_match('#^(?:https?:)?//#', $target) !== 1) {
                        $errs[] = 'CSS no permitido: @import con una ruta no válida.';
                    }
                }
            }
        }
        return array_values(array_unique($errs));
    }

    // ------------------------------------------------------------------- SVG ---

    /** @return list<string> */
    public static function scanSvg(string $svg): array
    {
        $errs = [];
        if (preg_match('/<!\s*(?:ENTITY|DOCTYPE\s[^>]*\[)/i', $svg) === 1) {
            return ['el SVG declara entidades o DTD (no permitido).'];
        }
        if (preg_match('/<\s*(?:script|foreignObject)\b/i', $svg) === 1) {
            $errs[] = 'el SVG contiene <script> o <foreignObject>.';
        }
        if (preg_match('/[\s"\'\/]on[a-z]+\s*=/i', $svg) === 1) {
            $errs[] = 'el SVG contiene manejadores de eventos (on*=).';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);   // sin LIBXML_NOENT
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null || strtolower($doc->documentElement->localName ?? '') !== 'svg') {
            return array_merge($errs, ['el SVG no es un XML válido con raíz <svg>.']);
        }
        foreach ($doc->getElementsByTagName('*') as $el) {
            $errs = array_merge($errs, self::checkElement($el, strtolower($el->localName ?? $el->nodeName)));
        }
        return $errs;
    }

    // --------------------------------------------------------------- helpers ---

    private static function rx(string $re, string $subject, string $rep): string
    {
        $r = preg_replace($re, $rep, $subject);
        if ($r === null) {
            throw new RuntimeException('Fallo del análisis (PCRE).');
        }
        return $r;
    }

    private static function safeName(string $name): string
    {
        $n = preg_replace('/[^A-Za-z0-9._\/-]/', '?', mb_substr($name, 0, 60)) ?? '?';
        return $n === '' ? 'archivo' : $n;
    }
}
