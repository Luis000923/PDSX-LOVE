<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/** Previsualiza una plantilla con datos de ejemplo. Nunca toca datos reales de usuarios. */
Admin::guard();

$st = db()->prepare('SELECT file, kind, slug FROM templates WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    exit('Plantilla no encontrada.');
}

$demo = [
    'your_name'    => 'Ana',
    'partner_name' => 'Luis',
    'start_date'   => (new DateTimeImmutable('-400 days'))->format('Y-m-d'),
    'message'      => "Este es un texto de ejemplo para previsualizar la plantilla.\nSegunda línea.",
];

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

try {
    echo Template::renderRow($row, $demo, [
        'nonce'   => csp_nonce(),
        'ad_slot' => '<div class="mt-8 min-h-[90px] rounded-xl border border-dashed border-rose-200 text-xs text-slate-400 flex items-center justify-center">Publicidad (ejemplo)</div>',
    ]);
} catch (Throwable $ex) {
    http_response_code(500);
    exit('No se pudo renderizar: ' . e($ex->getMessage()));
}
