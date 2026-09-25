<?php
declare(strict_types=1);

/** Utilidades para la descarga del HTML de una página (public/download.php). */
final class SiteExport
{
    private const MAX_INLINE = 2_000_000; // bytes por recurso incrustado

    /** Nombre de archivo seguro: "pagina-<slug>.html" con solo [a-z0-9-]. */
    public static function filename(string $slug): string
    {
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slug)), '-');
        return 'pagina-' . ($s !== '' ? substr($s, 0, 40) : 'amor') . '.html';
    }

    /**
     * Hace el HTML lo más autónomo posible: incrusta CSS/JS/imágenes locales de la plantilla
     * (public/assets/tpl/<slug>/...) y no expone rutas del servidor. Lo que no sea local
     * (Google Fonts, CDN) queda como URL externa: requiere conexión.
     */
    public static function selfContain(string $html, string $tplSlug, string $assetsDir, string $baseUrl): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $tplSlug)) {
            return $html;
        }
        $root = realpath($assetsDir . '/' . $tplSlug);
        if ($root === false) {
            return $html;
        }
        $prefix = rtrim($baseUrl, '/') . '/assets/tpl/' . $tplSlug . '/';
        $load = static function (string $ref) use ($root, $prefix): ?array {
            $ref = html_entity_decode($ref, ENT_QUOTES);
            if (!str_starts_with($ref, $prefix)) {
                return null;
            }
            $rel = rawurldecode((string) strtok(substr($ref, strlen($prefix)), '?#'));
            $f = realpath($root . '/' . $rel);
            if ($f === false || !str_starts_with($f, $root . DIRECTORY_SEPARATOR) || !is_file($f) || filesize($f) > self::MAX_INLINE) {
                return null;
            }
            return [strtolower(pathinfo($f, PATHINFO_EXTENSION)), (string) file_get_contents($f)];
        };

        $html = (string) preg_replace_callback('/<link\b[^>]*\bhref="([^"]+)"[^>]*>/i', static function ($m) use ($load) {
            if (!preg_match('/rel="stylesheet"/i', $m[0]) || !($r = $load($m[1])) || $r[0] !== 'css') {
                return $m[0];
            }
            return '<style>' . str_ireplace('</style', '<\/style', $r[1]) . '</style>';
        }, $html);

        $html = (string) preg_replace_callback('/<script\b([^>]*)\bsrc="([^"]+)"([^>]*)>\s*<\/script>/i', static function ($m) use ($load) {
            if (!($r = $load($m[2])) || $r[0] !== 'js') {
                return $m[0];
            }
            return '<script>' . str_ireplace('</script', '<\/script', $r[1]) . '</script>';
        }, $html);

        $mime = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        return (string) preg_replace_callback('/\b(src|href)="([^"]+)"|url\(([\'"]?)([^)\'"]+)\3\)/i', static function ($m) use ($load, $mime) {
            $ref = $m[2] !== '' ? $m[2] : $m[4];
            $r = $load($ref);
            if (!$r || !isset($mime[$r[0]]) || ($m[1] === 'href')) {
                return $m[0];
            }
            $uri = 'data:' . $mime[$r[0]] . ';base64,' . base64_encode($r[1]);
            return $m[1] !== '' ? 'src="' . $uri . '"' : 'url(' . $uri . ')';
        }, $html);
    }
}
