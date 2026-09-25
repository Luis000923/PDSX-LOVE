<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Vista previa pública de una plantilla activa con datos de ejemplo (nunca datos reales).
 * Se identifica por slug validado; se renderiza con el mismo motor que las páginas reales.
 */
$slug = $_GET['t'] ?? '';
$tpl = null;
if (is_string($slug) && preg_match('/^[a-z0-9][a-z0-9_-]{2,39}$/', $slug)) {
    $st = db()->prepare('SELECT id, slug, name, kind, file, price_usd, is_premium FROM templates WHERE slug = ? AND is_active = 1');
    $st->execute([$slug]);
    $tpl = $st->fetch() ?: null;
}
if ($tpl === null) {
    render_error(404);
}

$demo = [
    'your_name'    => 'Ana',
    'partner_name' => 'Luis',
    'start_date'   => (new DateTimeImmutable('-400 days'))->format('Y-m-d'),
    'message'      => "Así se verá tu página con tus propios datos.\nCada palabra la escribes tú.",
];

$embed = ($_GET['embed'] ?? '') === '1';
if ($embed) {
    $demo = Template::mergeDemo($demo, $_GET);
}

try {
    $html = Template::renderRow($tpl, $demo, ['nonce' => csp_nonce(), 'ad_slot' => '']);
} catch (Throwable $ex) {
    error_log('preview ' . $slug . ': ' . $ex->getMessage());
    render_error(500);
}

$loggedIn = current_user() !== null;
$use = e(url($loggedIn ? 'create.php?template=' . rawurlencode((string) $tpl['slug']) : 'register.php'));
$bar = '<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;justify-content:space-between;'
     . 'padding:10px max(16px,env(safe-area-inset-right)) calc(10px + env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));'
     . 'background:rgba(255,255,255,.97);border-top:1px solid #fecdd3;font:600 13px system-ui,sans-serif;color:#1e293b;max-width:100vw;box-sizing:border-box">'
     . '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1 1 auto;min-width:0">Vista previa · ' . e((string) $tpl['name']) . '</span>'
     . '<span style="display:flex;gap:8px;flex:0 0 auto">'
     . '<a href="' . e(url('index.php')) . '" style="color:#64748b;text-decoration:none;padding:10px 10px;min-height:44px;display:inline-flex;align-items:center">← Volver</a>'
     . '<a href="' . $use . '" style="background:#e11d48;color:#fff;text-decoration:none;border-radius:10px;padding:10px 14px;min-height:44px;display:inline-flex;align-items:center">Usar esta plantilla</a></span></div>';
if ($embed) {
    $bar = '';
} else {
    // Espacio para que la barra fija no tape el final del contenido en pantallas cortas.
    $bar = '<style>body{padding-bottom:calc(64px + env(safe-area-inset-bottom)) !important}</style>' . $bar;
}
$pos = strripos($html, '</body>');
$html = $pos === false ? $html . $bar : substr_replace($html, $bar, $pos, 0);

if ($embed) {
    // Solo mismo origen (el resto de páginas conserva DENY / 'none'); misma CSP que bootstrap salvo frame-ancestors.
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.tailwindcss.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; "
        . "frame-ancestors 'self'; base-uri 'none'; form-action 'self' https://*.wompi.sv");
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
echo $html;
