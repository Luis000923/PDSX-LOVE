<?php
declare(strict_types=1);

/** Layout del panel de administración: ancho, sobrio y visualmente distinto de la app. */

const ADMIN_INPUT_CLS = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
const ADMIN_BTN_CLS   = 'rounded-lg bg-slate-900 hover:bg-slate-700 text-white text-sm font-semibold px-4 py-2 transition';
const ADMIN_CARD_CLS  = 'rounded-xl bg-white border border-slate-200 p-5';

function admin_page_start(string $title, string $current = ''): void
{
    $n = e(csp_nonce());
    $t = e($title);
    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t} · Admin LovePages</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<script nonce="{$n}" src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Poppins',system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
<header class="bg-slate-900 text-slate-200">
  <div class="max-w-5xl mx-auto px-5 py-3 flex flex-wrap items-center gap-x-6 gap-y-2">
    <span class="font-bold text-white">♥ LovePages <span class="text-slate-400 font-normal">admin</span></span>
    <nav class="text-sm flex gap-4">
HTML;

    $links = [
        'index'      => ['admin/index.php', 'Dashboard'],
        'templates'  => ['admin/templates.php', 'Plantillas'],
        'promos'     => ['admin/promos.php', 'Promociones'],
    ];
    foreach ($links as $key => [$path, $label]) {
        $cls = $key === $current ? 'text-white font-semibold' : 'text-slate-400 hover:text-white';
        echo '<a class="' . $cls . '" href="' . e(url($path)) . '">' . e($label) . '</a>';
    }

    echo '</nav><div class="ml-auto text-sm flex gap-4 items-center">'
       . '<a class="text-slate-400 hover:text-white" href="' . e(url('dashboard.php')) . '">← Volver a la app</a>'
       . '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field()
       . '<button class="text-slate-400 hover:text-white">Salir</button></form>'
       . '</div></div></header>'
       . '<main class="max-w-5xl mx-auto px-5 py-8">';

    if ($m = flash()) {
        echo '<p class="mb-6 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-900">' . e($m) . '</p>';
    }
}

function admin_page_end(): void
{
    echo '</main></body></html>';
}

/** Lista de errores de validación. @param string[] $errors */
function admin_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="mb-6 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-900"><ul class="list-disc pl-4 space-y-1">';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div>';
}
