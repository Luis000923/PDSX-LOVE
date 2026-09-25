<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Renderizador público: /c/{slug} (o view.php?u={slug}). */
$slug = $_GET['u'] ?? '';
if (!is_string($slug) || !preg_match('/^[a-z0-9]{6,12}$/', $slug)) {
    http_response_code(404);
    exit('Página no encontrada.');
}

$st = db()->prepare(
    'SELECT s.data, t.file, u.is_premium
       FROM user_sites s
       JOIN templates t ON t.id = s.template_id AND t.is_active = 1
       JOIN users u     ON u.id = s.user_id
      WHERE s.slug = ?'
);
$st->execute([$slug]);
$site = $st->fetch();

if (!$site) {
    http_response_code(404);
    exit('Página no encontrada.');
}

$data = json_decode($site['data'], true);
if (!is_array($data)) {
    http_response_code(500);
    exit('Datos corruptos.');
}

// Anuncios solo para cuentas gratuitas y solo si el panel los tiene activados.
// El HTML del banner lo escribe un administrador y pasa por Admin::validateAdHtml().
$adSlot = '';
if (!$site['is_premium'] && Admin::setting('ads_enabled') === '1') {
    $banner = Admin::setting('ads_html');
    $adSlot = $banner !== ''
        ? '<div id="ad-slot" class="mt-8">' . $banner . '</div>'
        : '<div id="ad-slot" class="mt-8 min-h-[90px] rounded-xl border border-dashed border-rose-200 text-xs text-slate-400 flex items-center justify-center">Publicidad</div>';
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store'); // el nonce cambia por petición

echo Template::render($site['file'], $data, [
    'nonce'   => csp_nonce(),
    'ad_slot' => $adSlot,
]);
