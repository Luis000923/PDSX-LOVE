<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/SiteExport.php';

/**
 * Descarga el HTML de una página propia. Solo el propietario; sin anuncios.
 * Limitación: las fuentes/CDN externos de la plantilla (Google Fonts, etc.) siguen siendo URLs
 * externas (hace falta conexión para verlos); CSS, JS e imágenes locales se incrustan.
 */
$user = require_login();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($id) || $id < 1) {
    render_error(404);
}

$st = db()->prepare(
    'SELECT s.slug AS site_slug, s.data, s.expires_at, t.file, t.kind, t.slug
       FROM user_sites s
       JOIN templates t ON t.id = s.template_id AND (t.is_active = 1 OR (t.kind = \'utpl\' AND t.review_status = \'withdrawn\'))
      WHERE s.id = ? AND s.user_id = ?'
);
$st->execute([$id, $user['id']]);
$site = $st->fetch();
if (!$site) {
    render_error(404);
}
if (Access::isExpired($site['expires_at'] === null ? null : (string) $site['expires_at'])) {
    render_error(410);
}
$data = json_decode((string) $site['data'], true);
if (!is_array($data)) {
    render_error(500);
}

if (($site['kind'] ?? 'html') === 'user') {
    // HTML propio: se devuelve el archivo que subió su dueño (los recursos de un ZIP no van incluidos).
    $slug = (string) $site['site_slug'];
    $file = preg_match('/^[a-z0-9]{6,12}$/D', $slug) === 1 ? ROOT . '/storage/user_html/' . $slug . '/index.html' : '';
    if ($file === '' || !is_file($file) || ($html = file_get_contents($file)) === false) {
        render_error(404);
    }
} else {
    $html = Template::renderRow($site, $data, ['nonce' => '', 'ad_slot' => '']);
}
if (($site['kind'] ?? 'html') === 'php') {
    $html = SiteExport::selfContain($html, (string) $site['slug'], PhpTemplate::assetsDir(), url(''));
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . SiteExport::filename((string) $site['site_slug']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
echo $html;
