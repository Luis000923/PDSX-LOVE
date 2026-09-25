<?php
declare(strict_types=1);

/**
 * Layout y helpers visuales del panel de administración.
 * Barra lateral fija (escritorio) o barra superior con <details> (móvil, sin JS).
 */

const ADMIN_INPUT_CLS      = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500';
const ADMIN_BTN_CLS        = 'inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 hover:bg-slate-700 text-white text-sm font-semibold px-4 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2';
const ADMIN_BTN_GHOST_CLS  = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-semibold px-4 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2';
const ADMIN_BTN_DANGER_CLS = 'inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 bg-white hover:bg-rose-50 text-rose-700 text-sm font-semibold px-4 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2';
const ADMIN_CARD_CLS       = 'rounded-2xl bg-white border border-slate-200 shadow-sm p-5';

/** Grupos de navegación: [grupo => [clave => [ruta, etiqueta, icono]]]. */
function admin_nav_items(): array
{
    return [
        'Resumen'   => ['index' => ['admin/index.php', 'Dashboard', 'dashboard']],
        'Operación' => [
            'payments' => ['admin/payments.php', 'Pagos', 'payments'],
            'users'    => ['admin/users.php', 'Usuarios', 'users'],
            'pages'    => ['admin/pages.php', 'Páginas', 'pages'],
        ],
        'Catálogo'  => [
            'templates' => ['admin/templates.php', 'Plantillas', 'templates'],
            'promos'    => ['admin/promos.php', 'Cupones', 'coupon'],
            'awards'    => ['admin/awards.php', 'Premios', 'coins'],
            'creators'  => ['admin/creators.php', 'Creadores', 'users'],
        ],
        'Sistema'   => [
            'settings' => ['admin/settings.php', 'Ajustes', 'settings'],
            'activity' => ['admin/activity.php', 'Actividad', 'activity'],
        ],
    ];
}

function admin_nav_html(string $current): string
{
    $out = '<nav aria-label="Administración" class="space-y-5">';
    foreach (admin_nav_items() as $group => $items) {
        $out .= '<div><p class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">' . e($group) . '</p><ul class="space-y-0.5">';
        foreach ($items as $key => [$path, $label, $icon]) {
            $active = $key === $current;
            $cls = $active
                ? 'bg-slate-800 text-white border-rose-400 font-semibold'
                : 'text-slate-300 hover:bg-slate-800/60 hover:text-white border-transparent';
            $out .= '<li><a href="' . e(url($path)) . '"' . ($active ? ' aria-current="page"' : '')
                . ' class="flex items-center gap-3 rounded-lg border-l-4 px-3 py-2 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 ' . $cls . '">'
                . admin_icon($icon, 'light', 18) . '<span>' . e($label) . '</span></a></li>';
        }
        $out .= '</ul></div>';
    }
    return $out . '</nav>';
}

function admin_page_start(string $title, string $current = '', string $subtitle = '', string $actionsHtml = ''): void
{
    $n = e(csp_nonce());
    $t = e($title);
    $icon = e(url('assets/img/favicon.svg'));
    $logo = e(url('assets/img/admin/logo-admin.svg'));
    $home = e(url('admin/index.php'));
    $site = e(url('dashboard.php'));
    $logout = e(url('logout.php'));
    $csrf = csrf_field();
    $email = e((string) (current_user()['email'] ?? ''));
    $nav = admin_nav_html($current);
    $focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400';

    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t} · Admin LovePages</title>
<link rel="icon" type="image/svg+xml" href="{$icon}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<script nonce="{$n}" src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Poppins',system-ui,sans-serif}details>summary::-webkit-details-marker{display:none}.tabular{font-variant-numeric:tabular-nums}</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-sm">Saltar al contenido</a>

<aside class="hidden lg:flex fixed inset-y-0 left-0 w-64 flex-col bg-slate-900 text-slate-200">
  <a href="{$home}" class="flex items-center gap-2 px-5 h-16 shrink-0 border-b border-slate-800 {$focus}">
    <img src="{$logo}" alt="LovePages admin" width="150" height="32" class="h-8 w-auto">
  </a>
  <div class="flex-1 overflow-y-auto px-3 py-5">{$nav}</div>
  <div class="border-t border-slate-800 p-4 text-sm">
    <p class="truncate text-slate-400 text-xs mb-3" title="{$email}">{$email}</p>
    <div class="flex items-center justify-between gap-2">
      <a class="text-slate-300 hover:text-white underline-offset-2 hover:underline {$focus} rounded" href="{$site}">Ver sitio</a>
      <form method="post" action="{$logout}">{$csrf}<button class="rounded-lg border border-slate-700 px-3 py-1.5 text-slate-200 hover:bg-slate-800 {$focus}">Salir</button></form>
    </div>
  </div>
</aside>

<header class="lg:hidden bg-slate-900 text-slate-200 sticky top-0 z-40">
  <details class="group">
    <summary class="flex items-center justify-between px-4 h-14 cursor-pointer list-none {$focus}">
      <img src="{$logo}" alt="LovePages admin" width="130" height="28" class="h-7 w-auto">
      <span class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm">Menú</span>
    </summary>
    <div class="px-3 pb-4 pt-2 border-t border-slate-800 max-h-[80vh] overflow-y-auto">
      {$nav}
      <div class="mt-4 pt-4 border-t border-slate-800 text-sm flex items-center justify-between gap-2">
        <span class="truncate text-xs text-slate-400">{$email}</span>
        <a class="text-slate-300 underline {$focus} rounded" href="{$site}">Ver sitio</a>
        <form method="post" action="{$logout}">{$csrf}<button class="rounded-lg border border-slate-700 px-3 py-1.5 {$focus}">Salir</button></form>
      </div>
    </div>
  </details>
</header>

<main id="contenido" class="lg:pl-64">
<div class="max-w-6xl mx-auto px-4 sm:px-8 py-6 sm:py-8">
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div class="min-w-0">
    <h1 class="text-2xl font-bold text-slate-900">{$t}</h1>
HTML;
    if ($subtitle !== '') {
        echo '<p class="text-sm text-slate-500 mt-1">' . e($subtitle) . '</p>';
    }
    echo '</div>';
    if ($actionsHtml !== '') {
        echo '<div class="flex flex-wrap items-center gap-2">' . $actionsHtml . '</div>';
    }
    echo '</div>';

    if ($m = flash()) {
        echo '<p role="status" aria-live="polite" class="mb-6 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-900">' . e($m) . '</p>';
    }
}

function admin_page_end(): void
{
    echo '</div></main></body></html>';
}

/** Lista de errores de validación. @param string[] $errors */
function admin_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div role="alert" class="mb-6 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-900"><ul class="list-disc pl-4 space-y-1">';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div>';
}

// ---------------------------------------------------------------- helpers ---

/** Icono <img> de assets/img/admin/{name}[-dark|-accent].svg. variant: light (sin sufijo), dark, accent. */
function admin_icon(string $name, string $variant = 'dark', int $size = 20): string
{
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?? '';
    $suffix = match ($variant) {
        'dark'   => '-dark',
        'accent' => '-accent',
        default  => '',
    };
    return '<img src="' . e(url('assets/img/admin/' . $name . $suffix . '.svg')) . '" alt="" width="' . $size . '" height="' . $size . '" class="shrink-0" aria-hidden="true">';
}

function admin_badge(string $label, string $tone = 'slate'): string
{
    $tones = [
        'slate'  => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green'  => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'amber'  => 'bg-amber-50 text-amber-800 ring-amber-200',
        'rose'   => 'bg-rose-50 text-rose-800 ring-rose-200',
        'blue'   => 'bg-blue-50 text-blue-800 ring-blue-200',
        'violet' => 'bg-violet-50 text-violet-800 ring-violet-200',
    ];
    $cls = $tones[$tone] ?? $tones['slate'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset whitespace-nowrap ' . $cls . '">' . e($label) . '</span>';
}

function admin_stat(string $label, string $value, string $hint = '', string $icon = '', string $tone = 'slate'): string
{
    $ring = ['green' => 'bg-emerald-50', 'amber' => 'bg-amber-50', 'rose' => 'bg-rose-50', 'blue' => 'bg-blue-50', 'violet' => 'bg-violet-50'][$tone] ?? 'bg-slate-100';
    $out = '<div class="' . ADMIN_CARD_CLS . '"><div class="flex items-start justify-between gap-3"><div class="min-w-0">'
        . '<p class="text-xs font-semibold uppercase tracking-wide text-slate-500">' . e($label) . '</p>'
        . '<p class="text-2xl font-bold text-slate-900 mt-1 tabular">' . e($value) . '</p></div>';
    if ($icon !== '') {
        $out .= '<span class="rounded-xl p-2 ' . $ring . '">' . admin_icon($icon, 'dark', 20) . '</span>';
    }
    $out .= '</div>';
    if ($hint !== '') {
        $out .= '<p class="text-xs text-slate-500 mt-2">' . e($hint) . '</p>';
    }
    return $out . '</div>';
}

function admin_empty(string $message, string $hint = ''): string
{
    return '<div class="text-center py-8 px-4"><img src="' . e(url('assets/img/admin/empty-admin.svg')) . '" alt="" width="96" height="96" class="mx-auto mb-3 opacity-80">'
        . '<p class="text-sm font-semibold text-slate-700">' . e($message) . '</p>'
        . ($hint !== '' ? '<p class="text-xs text-slate-500 mt-1">' . e($hint) . '</p>' : '') . '</div>';
}

/** Paginación con enlaces ?page=N. $query: parámetros extra a conservar (los vacíos se omiten). */
function admin_pager(int $page, int $pages, string $baseUrl, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }
    unset($query['page']);
    $query = array_filter($query, static fn($v): bool => $v !== '' && $v !== null);
    $link = static function (int $p, string $label, bool $current = false, bool $disabled = false) use ($baseUrl, $query): string {
        $base = 'inline-flex min-w-9 justify-center rounded-lg border px-3 py-1.5 text-sm ';
        if ($disabled) {
            return '<span class="' . $base . 'border-slate-200 text-slate-300">' . $label . '</span>';
        }
        $href = url($baseUrl) . '?' . http_build_query($query + ['page' => $p]);
        $cls = $current ? 'bg-slate-900 border-slate-900 text-white font-semibold' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50';
        return '<a href="' . e($href) . '"' . ($current ? ' aria-current="page"' : '') . ' class="' . $base . $cls . ' focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">' . $label . '</a>';
    };
    $page = max(1, min($page, $pages));
    $nums = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn(int $p): bool => $p >= 1 && $p <= $pages));
    sort($nums);
    $out = '<nav aria-label="Paginación" class="flex flex-wrap items-center justify-center gap-1.5 mt-4">'
        . $link($page - 1, 'Anterior', false, $page <= 1);
    $prev = 0;
    foreach ($nums as $p) {
        if ($p - $prev > 1) {
            $out .= '<span class="px-1 text-slate-400" aria-hidden="true">…</span>';
        }
        $out .= $link($p, (string) $p, $p === $page);
        $prev = $p;
    }
    return $out . $link($page + 1, 'Siguiente', false, $page >= $pages) . '</nav>';
}

/** Centavos USD -> '$1,234.50'. */
function admin_money(int $cents): string
{
    return ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 2, '.', ',');
}

/** DATETIME UTC -> 'd/m/Y H:i' en hora de El Salvador (UTC-6, sin horario de verano). */
function admin_date(?string $utc, bool $withTime = true): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return '—';
    }
    return $d->setTimezone(new DateTimeZone('America/El_Salvador'))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
}

function admin_status_badge(string $paymentStatus): string
{
    return match (strtoupper($paymentStatus)) {
        'APPROVED' => admin_badge('Aprobado', 'green'),
        'PENDING'  => admin_badge('Pendiente', 'amber'),
        'DECLINED' => admin_badge('Rechazado', 'rose'),
        'VOIDED'   => admin_badge('Anulado', 'slate'),
        'ERROR'    => admin_badge('Error', 'rose'),
        default    => admin_badge($paymentStatus, 'slate'),
    };
}

/**
 * Abre una tabla limpia dentro de un contenedor con scroll horizontal.
 * $headers: lista de etiquetas; para alinear a la derecha usa la forma ['Monto' => 'right'].
 * $opts: 'caption' => texto accesible (sr-only).
 * Cierra con admin_table_close(). Las celdas: <td class="px-4 py-3">, números con clase "tabular".
 */
function admin_table_open(array $headers, array $opts = []): string
{
    $out = '<div class="overflow-x-auto"><table class="w-full text-sm">';
    if (!empty($opts['caption'])) {
        $out .= '<caption class="sr-only">' . e((string) $opts['caption']) . '</caption>';
    }
    $out .= '<thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr>';
    foreach ($headers as $k => $h) {
        $right = is_string($k) && $h === 'right';
        $label = $right ? $k : (string) $h;
        $out .= '<th scope="col" class="px-4 py-2.5 font-semibold sticky top-0 bg-slate-50' . ($right ? ' text-right' : '') . '">' . e($label) . '</th>';
    }
    return $out . '</tr></thead><tbody class="divide-y divide-slate-100 [&>tr:hover]:bg-slate-50/70">';
}

function admin_table_close(): string
{
    return '</tbody></table></div>';
}
