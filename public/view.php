<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** Renderizador público: /c/{slug} (o view.php?u={slug}). */
$slug = $_GET['u'] ?? '';
if (!is_string($slug) || !preg_match('/^[a-z0-9]{6,12}$/D', $slug)) {
    render_error(404);
}

$st = db()->prepare(
    'SELECT s.id AS site_id, s.slug AS site_slug, s.data, s.expires_at, t.file, t.kind, t.slug, u.is_premium, u.is_suspended, u.membership_tier_id, ((mt.ad_free = 1 AND (u.membership_expires_at IS NULL OR u.membership_expires_at > UTC_TIMESTAMP()))
               OR (bmt.ad_free = 1 AND u.bonus_tier_expires_at > UTC_TIMESTAMP())) AS ad_free
       FROM user_sites s
       JOIN templates t ON t.id = s.template_id AND (t.is_active = 1 OR (t.kind = \'utpl\' AND t.review_status = \'withdrawn\'))
       JOIN users u     ON u.id = s.user_id
  LEFT JOIN membership_tiers mt ON mt.id = u.membership_tier_id
  LEFT JOIN membership_tiers bmt ON bmt.id = u.bonus_tier_id
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

// HTML propio del usuario y plantillas públicas de usuario (utpl): nunca se renderiza aquí. Esta página NUESTRA (con la CSP de bootstrap, sin scripts propios)
// solo enmarca /c/{slug}/f en un iframe sandbox SIN allow-same-origin; el documento del usuario lo sirve frame.php con su
// propia CSP `sandbox`. El banner del plan gratuito y el enlace de reporte van fuera del iframe.
if (in_array((string) $site['kind'], ['user', 'utpl'], true)) {
    $title = trim((string) ($data['your_name'] ?? '')) !== '' ? (string) $data['your_name'] : 'Página';
    $mail  = trim((string) env('LEGAL_EMAIL', ''));
    $report = $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)
        ? '<a href="mailto:' . e($mail) . '?subject=' . rawurlencode('Reporte de página ' . $slug) . '">Reportar esta página</a>'
        : '<span>Para reportar esta página, escríbenos desde pdsx.org/love</span>';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    $n = e(csp_nonce());
    $tw = $adSlot !== '' ? '<script nonce="' . $n . '" src="https://cdn.tailwindcss.com"></script>' : '';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<meta name="robots" content="noindex"><title>' . e($title) . ' · LovePages</title>' . $tw
       . '<style>html,body{height:100%;margin:0}body{display:flex;flex-direction:column;height:100dvh;overflow:hidden;background:#fff1f2;font:12px/1.4 system-ui,sans-serif}'
       . 'iframe{flex:1 1 auto;min-height:0;width:100%;border:0;display:block;background:#fff}'
       . '.bar{flex:none;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:2px 12px;padding:4px 12px calc(4px + env(safe-area-inset-bottom));color:#64748b}'
       . '.bar a{color:#64748b;text-decoration:underline;padding:6px 0}.ads{flex:none;max-height:25dvh;overflow:auto;padding:0 12px}</style></head><body>'
       . '<iframe src="' . e(url('c/' . $slug . '/f')) . '" sandbox="allow-scripts" referrerpolicy="no-referrer" title="' . e('Página de ' . $title) . '"></iframe>'
       . ($adSlot !== '' ? '<div class="ads">' . $adSlot . '</div>' : '')
       . '<div class="bar">' . $report . '</div></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store'); // el nonce cambia por petición

echo Template::renderRow($site, $data, [
    'nonce'   => csp_nonce(),
    'ad_slot' => $adSlot,
]);
