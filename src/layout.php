<?php
declare(strict_types=1);

/** Layout compartido de las páginas de la app (no de las plantillas de pareja). */
function page_start(string $title, string $width = 'max-w-md'): void
{
    $w = e($width);
    $n = e(csp_nonce());
    $icon = e(url('assets/img/favicon.svg'));
    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title} · LovePages</title>
<link rel="icon" type="image/svg+xml" href="{$icon}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<script nonce="{$n}" src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Poppins',system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-rose-50 text-slate-800 antialiased">
<header class="{$w} mx-auto px-5 py-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
  <a href="
HTML;
    echo e(url('index.php')) . '" class="flex items-center gap-2" aria-label="PDSX · LovePages">' . pdsx_logo(28)
       . '<span class="text-sm font-semibold text-rose-600 border-l border-rose-200 pl-2">love</span></a><nav class="text-sm flex flex-wrap items-center justify-end gap-x-4 gap-y-1">';
    if ($u = current_user()) {
        echo '<a class="text-slate-600" href="' . e(url('index.php')) . '">Galería</a>'
           . '<a class="text-slate-600" href="' . e(url('tienda.php')) . '">Tienda</a>'
           . '<a class="text-slate-600" href="' . e(url('top.php')) . '">Top</a>'
           . '<a class="text-slate-600" href="' . e(url('colaboradores.php')) . '">Creadores</a>'
           . '<a class="text-slate-600" href="' . e(url('dashboard.php')) . '">Mis páginas</a>'
           . '<a class="text-slate-600" href="' . e(url('profile.php')) . '">Perfil</a>';
        if ((int) ($u['is_admin'] ?? 0) === 1) {
            echo '<a class="text-slate-500" href="' . e(url('admin/index.php')) . '">Admin</a>';
        }
        echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field()
           . '<button class="text-slate-500">Salir</button></form>';
    } else {
        echo '<a class="text-slate-600" href="' . e(url('index.php')) . '">Galería</a><a class="text-slate-600" href="' . e(url('tienda.php')) . '">Tienda</a><a class="text-slate-600" href="' . e(url('top.php')) . '">Top</a><a class="text-slate-600" href="' . e(url('colaboradores.php')) . '">Creadores</a><a class="text-slate-600" href="' . e(url('login.php')) . '">Entrar</a>';
    }
    echo '</nav></header>';

    // Aviso global configurable desde el panel (texto plano, siempre escapado).
    if (Admin::setting('announcement_enabled') === '1' && ($note = Admin::setting('announcement_text')) !== '') {
        echo '<p class="' . $w . ' mx-auto px-5 py-2 text-center text-sm bg-rose-600 text-white rounded-b-xl">' . e($note) . '</p>';
    }

    echo '<main class="' . $w . ' mx-auto px-5 pb-16">';
    if ($m = flash()) {
        echo '<p class="mb-4 rounded-xl bg-white border border-rose-200 px-4 py-3 text-sm">' . e($m) . '</p>';
    }
}

/** Logotipo PDSX en SVG puro e inline (sin petición extra; hereda el color del texto). El punto es el acento. */
function pdsx_logo(int $height = 28): string
{
    $w = (int) round($height * 2.5);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 32" width="' . $w . '" height="' . $height . '" fill="none" role="img" aria-hidden="true">'
        . '<g stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-slate-900">'
        . '<path d="M5 9v19"/><circle cx="11.5" cy="15.5" r="6.5"/><circle cx="30" cy="15.5" r="6.5"/><path d="M36.5 3v19"/>'
        . '<g transform="translate(-4)"><path d="M57.5 10.5C55 8.5 47.5 8.5 47.5 12.5c0 4 10.5 2 10.5 6.5 0 4-8 4-10.5 1.5"/><path d="M63 9l12 13M75 9L63 22"/></g></g>'
        . '<circle cx="76" cy="21" r="2.6" fill="#f43f5e"/></svg>';
}

/**
 * Estado vacío con ilustración SVG (public/assets/img/empty-<kind>.svg), título, texto y acción opcional.
 * $kind es de lista blanca; título y texto se escapan.
 */
function empty_state(string $kind, string $title, string $text, ?string $href = null, ?string $label = null): string
{
    $kind = in_array($kind, ['pages', 'coins', 'search'], true) ? $kind : 'search';
    return '<div class="col-span-full text-center py-10"><img class="mx-auto" src="' . e(url('assets/img/empty-' . $kind . '.svg'))
        . '" alt="" width="140" height="110"><p class="mt-4 font-semibold">' . e($title) . '</p><p class="mt-1 text-sm text-slate-500">' . e($text) . '</p>'
        . ($href !== null && $label !== null ? '<a class="mt-4 inline-block rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold px-5 py-2.5 text-sm transition" href="' . e($href) . '">' . e($label) . '</a>' : '')
        . '</div>';
}

function page_end(): void
{
    echo '</main><footer class="mx-auto max-w-5xl px-5 py-8 text-center text-xs text-slate-500">'
       . '<a class="hover:text-rose-700 hover:underline" href="' . e(url('terms.php')) . '">Términos y condiciones</a>'
       . '<span class="mx-2 text-slate-300" aria-hidden="true">·</span>'
       . '<a class="hover:text-rose-700 hover:underline" href="' . e(url('privacy.php')) . '">Política de privacidad</a></footer></body></html>';
}

/** Clases reutilizables de formulario. */
const INPUT_CLS = 'w-full rounded-xl border border-rose-200 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-rose-400';
const BTN_CLS   = 'w-full rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold py-3 transition';

/**
 * Destino tras registrarse/entrar. Solo admite valores de una lista blanca (nunca una URL
 * arbitraria), así que no hay redirección abierta.
 */
function auth_next(string $default): string
{
    $next = is_string($_GET['next'] ?? null) ? $_GET['next'] : '';   // ?next[]=x (array) se trata como vacío
    return match ($next) {
        'premium' => 'dashboard.php?offer=1#premium',
        'code'    => 'dashboard.php?offer=code#premium',
        default   => $default,
    };
}

/** "?next=..." para conservar la intención de pago al saltar entre registro y login. */
function auth_next_qs(): string
{
    $n = is_string($_GET['next'] ?? null) ? $_GET['next'] : '';
    return in_array($n, ['premium', 'code'], true) ? '?next=' . $n : '';
}

/**
 * Planes de membresía (vigencia de 1 o 2 meses, sin renovación automática). Cada nivel es una tarjeta con su beneficio y su botón de pago;
 * debajo, un desplegable (sin JavaScript) para pagar un plan con código de promoción.
 * Sin sesión, los botones llevan a crear la cuenta y de vuelta aquí.
 */
function premium_offer(?array $user, ?string $lastPayment = null, bool $codeOpen = false): void
{
    $action  = e(url('checkout_wompi.php'));
    $tiers   = Access::tiers();
    $current = $user !== null ? Access::mainTier($user) : null;
    $rank    = (int) ($current['sort_order'] ?? 0);
    $img     = static fn (string $n, int $s, string $cls = ''): string
        => '<img src="' . e(url('assets/img/tienda/' . $n . '.svg')) . '" alt="" width="' . $s . '" height="' . $s . '" loading="lazy" class="' . $cls . '">';

    $expiresAt = $user !== null ? Access::membershipExpiresAt($user) : null;
    $daysLeft  = $user !== null ? Access::membershipDaysLeft($user) : null;
    $expiryTxt = $expiresAt !== null
        ? 'vence el ' . date('d/m/Y', (int) strtotime($expiresAt . ' UTC')) . ($daysLeft !== null ? ' (' . $daysLeft . ($daysLeft === 1 ? ' día)' : ' días)') : ')')
        : 'sin vencimiento';

    echo '<section id="premium" class="mt-6" aria-labelledby="premium-h"><p class="text-center text-xs font-semibold tracking-widest uppercase text-rose-600">Membresías · sin renovación automática</p>'
       . '<h2 id="premium-h" class="sr-only">Elige tu plan</h2>';
    if ($user !== null && Access::wasMember($user)) {
        echo '<p role="status" class="mt-3 rounded-xl bg-amber-50 text-amber-800 text-sm px-3 py-2">Tu plan venció. Renuévalo para recuperar tus beneficios.</p>';
    }

    if ($lastPayment === 'PENDING') {
        echo '<p role="status" class="mt-3 rounded-xl bg-amber-50 text-amber-800 text-sm px-3 py-2">Estamos confirmando tu pago… recarga en unos segundos.</p>';
    } elseif (in_array($lastPayment, ['DECLINED', 'ERROR', 'VOIDED'], true)) {
        echo '<p role="alert" class="mt-3 rounded-xl bg-rose-50 text-rose-700 text-sm px-3 py-2">Tu último pago no se completó. Puedes intentarlo de nuevo.</p>';
    }

    echo '<div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3 md:items-stretch">';
    foreach ($tiers as $t) {
        $so       = (int) $t['sort_order'];
        $featured = $so === 2;
        $owned    = $rank >= $so;
        $buyable  = $user !== null && Access::canPurchaseTier($user, $t);
        $isCurrent = $current !== null && $rank === $so;
        $months   = Access::tierMonths($t);
        $period   = Access::tierDurationLabel($t);
        $price    = e(wompi_format_usd(Access::tierPriceInCents($t)));
        $emblem   = 'plan-' . max(1, min(3, $so));
        $bonusPct = (int) $t['topup_bonus_pct'];
        $adFree   = (int) $t['ad_free'] === 1;
        echo '<article class="relative flex flex-col rounded-3xl bg-white p-5 shadow-sm border '
           . ($featured ? 'border-rose-400 ring-2 ring-rose-200 shadow-md md:-translate-y-2' : 'border-rose-100') . '">';
        if ($featured) {
            echo '<span class="absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-rose-600 text-white text-[11px] font-semibold uppercase tracking-wide px-3 py-1 shadow-sm">Más popular</span>';
        }
        echo '<div class="flex items-center gap-3 md:flex-col md:items-start">' . $img($emblem, 64, 'h-16 w-16 shrink-0')
           . '<h3 class="text-lg font-bold text-slate-900">' . e((string) $t['name']) . '</h3></div>'
           . '<p class="mt-3 text-4xl font-extrabold text-slate-900">$' . $price
           . ' <span class="text-xs font-medium text-slate-500">USD · ' . e($period) . '</span></p>'
           . '<hr class="my-4 border-rose-100"><ul class="space-y-2.5 text-sm">';
        foreach ([
            ['ico-pages', 'Hasta ' . (int) $t['max_sites'] . ' páginas activas', false],
            ['ico-clock', 'Cada página dura ' . (int) $t['site_days'] . ' días', false],
            ['ico-pages', (int) ($t['html_uploads_per_month'] ?? 0) . ' subidas de HTML propio al mes (el plan gratuito incluye ' . Access::FREE_MONTHLY_HTML_UPLOADS . ')', false],
            ['ico-coin', (int) $t['bonus_coins'] . ' monedas de bono inicial', false],
            (int) ($t['template_unlocks_per_month'] ?? 0) > 0
                ? ['ico-sparkle', (int) $t['template_unlocks_per_month'] . ' plantilla' . ((int) $t['template_unlocks_per_month'] === 1 ? '' : 's') . ' de membresía al mes (luego, con monedas)', false]
                : ['ico-sparkle', 'Plantillas de membresía siempre con monedas', true],
            $bonusPct > 0
                ? ['ico-sparkle', '+' . $bonusPct . ' % extra de monedas en cada recarga (se suma al bono del paquete)', false]
                : ['ico-sparkle', 'Recargas con el bono normal de cada paquete', true],
            $adFree ? ['ico-ban', 'Sin anuncios', false] : ['ico-ban', 'Con anuncios', true],
        ] as [$ico, $txt, $dim]) {
            echo '<li class="flex items-start gap-2.5 ' . ($dim ? 'text-slate-400' : 'text-slate-700') . '">'
               . $img($ico, 24, 'h-5 w-5 mt-0.5 shrink-0' . ($dim ? ' opacity-40' : '')) . '<span>' . e($txt) . '</span></li>';
        }
        echo '</ul><div class="mt-auto pt-5">';
        if ($user === null) {
            echo '<a href="' . e(url('register.php?next=premium')) . '" class="block text-center ' . BTN_CLS . '">Elegir ' . e((string) $t['name']) . '</a>';
        } elseif ($owned && !$isCurrent) {
            echo '<p class="flex items-center justify-center gap-1.5 min-h-[44px] text-sm font-semibold text-emerald-700">' . $img('ico-check', 24, 'h-5 w-5')
               . 'Incluido en tu plan</p>';
        } else {
            if ($isCurrent) {
                echo '<p class="mb-3 flex items-start justify-center gap-1.5 text-sm font-semibold text-emerald-700">' . $img('ico-check', 24, 'h-5 w-5 mt-0.5 shrink-0')
                   . '<span>Tu plan actual · ' . e($expiryTxt) . '</span></p>';
            }
            if ($buyable) {
                $label = $isCurrent ? 'Renovar +' . $months . ($months === 1 ? ' mes' : ' meses') . ' · $' . $price : ($rank > 0 ? 'Mejorar a ' . (string) $t['name'] . ' · $' . $price : 'Pagar $' . $price . ' con tarjeta');
                echo '<form method="post" action="' . $action . '">' . csrf_field() . '<input type="hidden" name="tier_id" value="' . (int) $t['id']
                   . '"><button class="w-full ' . BTN_CLS . '">' . e($label) . '</button></form>';
            }
        }
        echo '</div></article>';
    }
    echo '</div>';

    $promoTiers = $user !== null ? array_values(array_filter($tiers, static fn (array $t): bool => Access::canPurchaseTier($user, $t))) : [];
    if ($user !== null && $promoTiers !== []) {
        echo '<details class="group mt-6 rounded-2xl border border-rose-200 bg-white shadow-sm md:mx-auto md:max-w-xl"' . ($codeOpen ? ' open' : '') . '>'
           . '<summary class="flex items-center gap-3 cursor-pointer list-none min-h-[48px] px-4 py-3 font-semibold text-rose-700 rounded-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">'
           . $img('ico-ticket', 24, 'h-6 w-6 shrink-0') . '<span class="flex-1">Tengo un código de promoción</span>'
           . '<span class="transition group-open:rotate-180" aria-hidden="true">⌄</span></summary>'
           . '<form method="post" action="' . $action . '" class="px-4 pb-4 pt-1 space-y-3 border-t border-rose-100">' . csrf_field()
           . '<label class="sr-only" for="promo-tier">Plan</label><select id="promo-tier" name="tier_id" class="mt-3 ' . INPUT_CLS . '">';
        foreach ($promoTiers as $t) {
            echo '<option value="' . (int) $t['id'] . '">' . e((string) $t['name']) . ' · $' . e(wompi_format_usd(Access::tierPriceInCents($t))) . ' · ' . e(Access::tierDurationLabel($t)) . '</option>';
        }
        echo '</select><label class="sr-only" for="promo">Código de promoción</label>'
           . '<input id="promo" name="promo" class="' . INPUT_CLS . ' uppercase tracking-wider" maxlength="32" required'
           . ' pattern="[A-Za-z0-9\-]{3,32}" autocomplete="off" placeholder="Ej.: AMOR30"' . ($codeOpen ? ' autofocus' : '') . '>'
           . '<button class="w-full ' . BTN_CLS . '">Aplicar código y pagar</button></form></details>';
    } elseif ($user === null) {
        echo '<p class="mt-5 text-sm text-center text-slate-600">¿Ya tienes cuenta? <a class="text-rose-700 font-semibold underline-offset-2 hover:underline" href="' . e(url('login.php?next=premium')) . '">Entrar</a> · '
           . '<a class="text-rose-700 font-semibold underline-offset-2 hover:underline" href="' . e(url('register.php?next=code')) . '">Tengo un código</a></p>';
    }
    echo '<p class="mt-5 flex items-center justify-center gap-1.5 text-xs text-slate-500">' . $img('ico-shield', 24, 'h-4 w-4')
       . 'Pago seguro con tarjeta a través de Wompi · Sin renovación automática</p></section>';
}
