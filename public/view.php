<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Renderizador público: /c/{slug} (o view.php?u={slug}). */
$slug = $_GET['u'] ?? '';
if (!is_string($slug) || !preg_match('/^[a-z0-9]{6,12}$/', $slug)) {
    render_error(404);
}

$st = db()->prepare(
    'SELECT s.data, s.expires_at, t.file, t.kind, t.slug, u.is_premium, u.is_suspended, u.membership_tier_id, (mt.ad_free = 1 AND (u.membership_expires_at IS NULL OR u.membership_expires_at > UTC_TIMESTAMP())) AS ad_free
       FROM user_sites s
       JOIN templates t ON t.id = s.template_id AND t.is_active = 1
       JOIN users u     ON u.id = s.user_id
  LEFT JOIN membership_tiers mt ON mt.id = u.membership_tier_id
      WHERE s.slug = ?'
);
$st->execute([$slug]);
$site = $st->fetch();

if (!$site || (int) $site['is_suspended'] === 1) {   // cuenta suspendida: mensaje neutro, igual que un enlace inexistente
    render_error(404);
}

if (Access::isExpired($site['expires_at'] === null ? null : (string) $site['expires_at'])) {
    render_error(410);
}

$data = json_decode($site['data'], true);
if (!is_array($data)) {
    error_log("Datos corruptos en la página $slug");
    render_error(500);
}

// Anuncios solo para cuentas sin un nivel libre de anuncios y solo si el panel los tiene activados.
// El HTML del banner lo escribe un administrador y pasa por Admin::validateAdHtml().
$adSlot = '';
if (!(int) $site['ad_free'] && Admin::setting('ads_enabled') === '1') {
    $banner = Admin::setting('ads_html');
    $adSlot = $banner !== ''
        ? '<div id="ad-slot" class="mt-8">' . $banner . '</div>'
        : '<div id="ad-slot" class="mt-8 min-h-[90px] rounded-xl border border-dashed border-rose-200 text-xs text-slate-400 flex items-center justify-center">Publicidad</div>';
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store'); // el nonce cambia por petición

echo Template::renderRow($site, $data, [
    'nonce'   => csp_nonce(),
    'ad_slot' => $adSlot,
]);
