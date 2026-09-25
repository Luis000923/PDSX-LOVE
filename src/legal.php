<?php
declare(strict_types=1);

/**
 * Páginas legales (términos y privacidad): datos del responsable y maquetación común.
 * Los cuerpos son HTML de confianza escrito en public/terms.php y public/privacy.php (no datos de usuario).
 */

/** Fecha de la versión vigente de los documentos legales (Y-m-d). Actualizar al cambiar el texto. */
const LEGAL_UPDATED = '2026-09-25';

/** Nombre del responsable del servicio (LEGAL_ENTITY en .env; por defecto la marca). */
function legal_entity(): string
{
    $v = trim((string) env('LEGAL_ENTITY', ''));
    return $v !== '' ? $v : 'PDSX';
}

/** Enlace de contacto legal: correo si LEGAL_EMAIL está definido y es válido; si no, texto neutro. */
function legal_contact_html(): string
{
    $mail = trim((string) env('LEGAL_EMAIL', ''));
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>';
    }
    return 'el correo de contacto indicado en el sitio (<strong>pdsx.org/love</strong>)';
}

/** Duraciones de las páginas por plan, leídas de la BD para no quedar desfasadas: «Gratuito: 3 días · Romántico: 3 días · …». */
function legal_plan_durations_html(): string
{
    $rows = ['Plan gratuito: ' . Access::FREE_SITE_DAYS . ' días por página (hasta ' . Access::FREE_MONTHLY_PAGES . ' páginas al mes)'];
    foreach (Access::tiers() as $t) {
        $rows[] = e((string) $t['name']) . ': ' . (int) $t['site_days'] . ' días';
    }
    return '<ul><li>' . implode('</li><li>', $rows) . '</li></ul>';
}

/** Vigencia de cada membresía (meses), leída de la BD. */
function legal_plan_periods_html(): string
{
    $rows = [];
    foreach (Access::tiers() as $t) {
        $rows[] = e((string) $t['name']) . ': ' . e(Access::tierDurationLabel($t));
    }
    return '<ul><li>' . implode('</li><li>', $rows) . '</li></ul>';
}

/**
 * Pinta una página legal con índice y apartados numerados.
 *
 * @param array<string,array{0:string,1:string}> $sections id => [título, cuerpo HTML de confianza]
 * @param array{0:string,1:string} $other [etiqueta, ruta] del otro documento legal
 */
function legal_page(string $title, string $lead, array $sections, array $other): void
{
    page_start($title, 'max-w-3xl');
    ?>
<article class="mt-4">
  <p class="text-xs font-semibold tracking-widest uppercase text-rose-600">Legal</p>
  <h1 class="text-2xl sm:text-3xl font-bold mt-1"><?= e($title) ?></h1>
  <p class="mt-2 text-sm text-slate-600">Última actualización: <time datetime="<?= e(LEGAL_UPDATED) ?>"><?= e(date('d/m/Y', (int) strtotime(LEGAL_UPDATED))) ?></time></p>
  <p class="mt-3 text-sm text-slate-700"><?= $lead ?></p>

  <nav aria-label="Contenido" class="mt-6 rounded-2xl bg-white border border-rose-100 p-4">
    <h2 class="text-sm font-semibold">Contenido</h2>
    <ol class="mt-2 grid sm:grid-cols-2 gap-x-6 gap-y-1 text-sm list-decimal list-inside marker:text-rose-400">
      <?php foreach ($sections as $id => [$t]): ?>
        <li><a class="text-rose-700 hover:underline" href="#<?= e($id) ?>"><?= e($t) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <div class="mt-6 space-y-4">
    <?php $n = 0; foreach ($sections as $id => [$t, $body]): $n++; ?>
      <section id="<?= e($id) ?>" class="scroll-mt-4 rounded-2xl bg-white border border-rose-100 p-5" aria-labelledby="h-<?= e($id) ?>">
        <h2 id="h-<?= e($id) ?>" class="font-semibold"><span class="text-rose-500"><?= $n ?>.</span> <?= e($t) ?></h2>
        <div class="mt-2 text-sm leading-relaxed text-slate-700 space-y-2 [&_a]:text-rose-700 [&_a]:underline [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1"><?= $body ?></div>
      </section>
    <?php endforeach; ?>
  </div>

  <p class="mt-6 text-sm flex flex-wrap gap-x-6 gap-y-2">
    <a class="text-rose-700 font-semibold hover:underline" href="<?= e(url($other[1])) ?>"><?= e($other[0]) ?></a>
    <a class="text-slate-600 hover:underline" href="<?= e(url('index.php')) ?>">← Volver al inicio</a>
  </p>
</article>
<?php
    page_end();
}
