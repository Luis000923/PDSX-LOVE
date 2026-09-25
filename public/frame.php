<?php
declare(strict_types=1);

/**
 * Documento aislado del HTML propio: /c/{slug}/f (rewrite) → frame.php?u={slug}.
 *
 * NO inicia sesión (ni envía cookies) y sustituye la CSP de la app por una propia con `sandbox` SIN allow-same-origin:
 * el navegador trata el documento como origen opaco, así que no lee cookies, ni el DOM del padre, ni llama a nuestra
 * API con credenciales, aunque alguien abra esta URL directamente. Solo view.php lo enmarca (iframe sandbox).
 */
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';
require_once ROOT . '/src/HtmlScanner.php';
require_once ROOT . '/src/UserHtml.php';

// Cabeceras mínimas para las respuestas de error (se reemplazan por las del documento si todo va bien).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'");

$slug = $_GET['u'] ?? '';
if (!is_string($slug) || !preg_match('/^[a-z0-9]{6,12}$/D', $slug)) {
    render_error(404);
}

$st = db()->prepare(
    'SELECT s.id AS site_id, s.slug AS site_slug, s.data, s.expires_at, u.is_suspended, t.kind, t.slug AS tpl_slug, h.has_assets
       FROM user_sites s
       JOIN templates t ON t.id = s.template_id
        AND ((t.is_active = 1 AND t.kind = \'user\') OR (t.kind = \'utpl\' AND (t.is_active = 1 OR t.review_status = \'withdrawn\')))
       JOIN users u ON u.id = s.user_id
  LEFT JOIN user_html_sites h ON h.site_id = s.id
      WHERE s.slug = ?'
);
$st->execute([$slug]);
$site = $st->fetch();

if (!$site || (int) $site['is_suspended'] === 1) {
    render_error(404);
}
if (Access::isExpired($site['expires_at'] === null ? null : (string) $site['expires_at'])) {
    render_error(410);
}
if ($site['kind'] === 'utpl') {
    // Plantilla pública de usuario (revisada, pero escrita por un tercero): mismo motor de marcadores, valores escapados,
    // y el documento se sirve con la MISMA cabecera de aislamiento (CSP sandbox sin allow-same-origin) que el HTML propio.
    $data = json_decode((string) $site['data'], true);
    try {
        $html = is_array($data)
            ? Creators::render((string) $site['tpl_slug'], $data, ['images' => TemplateImages::forSite((int) $site['site_id'], (string) $site['site_slug'])])
            : null;
    } catch (Throwable $ex) {
        error_log('utpl ' . $slug . ': ' . $ex->getMessage());
        $html = null;
    }
} elseif ($site['has_assets'] === null) {
    $html = null;
} else {
    $d = json_decode((string) $site['data'], true);
    $html = UserHtml::document($slug, (int) $site['has_assets'] === 1, TemplateImages::forSite((int) $site['site_id'], $slug), is_array($d) ? (string) ($d['your_name'] ?? '') : '');
}
if ($html === null) {
    error_log("HTML propio sin archivo en la página $slug");
    render_error(404);
}

UserHtml::sendSandboxHeaders();
echo $html;
