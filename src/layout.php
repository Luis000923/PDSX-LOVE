<?php
declare(strict_types=1);

/** Layout compartido de las páginas de la app (no de las plantillas de pareja). */
function page_start(string $title): void
{
    $n = e(csp_nonce());
    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title} · LovePages</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<script nonce="{$n}" src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Poppins',system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-rose-50 text-slate-800 antialiased">
<header class="max-w-md mx-auto px-5 py-4 flex items-center justify-between">
  <a href="
HTML;
    echo e(url('index.php')) . '" class="font-bold text-rose-600 text-lg">♥ LovePages</a><nav class="text-sm flex gap-4">';
    if ($u = current_user()) {
        echo '<a class="text-slate-600" href="' . e(url('dashboard.php')) . '">Mis páginas</a>';
        if ((int) ($u['is_admin'] ?? 0) === 1) {
            echo '<a class="text-slate-500" href="' . e(url('admin/index.php')) . '">Admin</a>';
        }
        echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field()
           . '<button class="text-slate-500">Salir</button></form>';
    } else {
        echo '<a class="text-slate-600" href="' . e(url('login.php')) . '">Entrar</a>';
    }
    echo '</nav></header>';

    // Aviso global configurable desde el panel (texto plano, siempre escapado).
    if (Admin::setting('announcement_enabled') === '1' && ($note = Admin::setting('announcement_text')) !== '') {
        echo '<p class="max-w-md mx-auto px-5 py-2 text-center text-sm bg-rose-600 text-white rounded-b-xl">' . e($note) . '</p>';
    }

    echo '<main class="max-w-md mx-auto px-5 pb-16">';
    if ($m = flash()) {
        echo '<p class="mb-4 rounded-xl bg-white border border-rose-200 px-4 py-3 text-sm">' . e($m) . '</p>';
    }
}

function page_end(): void
{
    echo '</main></body></html>';
}

/** Clases reutilizables de formulario. */
const INPUT_CLS = 'w-full rounded-xl border border-rose-200 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-rose-400';
const BTN_CLS   = 'w-full rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold py-3 transition';
