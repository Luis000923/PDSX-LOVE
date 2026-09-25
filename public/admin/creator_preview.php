<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_once ROOT . '/src/UserHtml.php';

/**
 * Vista previa AISLADA de una plantilla de usuario para el admin (pendiente o no): documento con CSP `sandbox`
 * (origen opaco, sin cookies ni acceso al panel), renderizado con datos de ejemplo. Nunca en origen propio.
 */
Admin::guard();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$st = db()->prepare("SELECT slug FROM templates WHERE id = ? AND kind = 'utpl'");
$st->execute([is_int($id) ? $id : 0]);
$slug = $st->fetchColumn();
$html = is_string($slug) ? Creators::reviewHtml($slug) : null;
if ($html === null) {
    render_error(404);
}
try {
    $doc = Template::renderString($html, Creators::demoData() + ['days_together' => (string) Template::daysTogether(Creators::demoData()['start_date'])], ['images' => []]);
} catch (Throwable $e) {
    render_error(500);
}
UserHtml::sendSandboxHeaders();
echo $doc;
