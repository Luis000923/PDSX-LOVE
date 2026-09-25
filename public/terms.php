<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/legal.php';

/** Términos y condiciones de uso de LovePages. Las duraciones por plan se leen de la BD (ver legal_plan_durations_html). */
$who = e(legal_entity());
$contact = legal_contact_html();
$durations = legal_plan_durations_html();
$periods = legal_plan_periods_html();
// Cupos de subidas de HTML propio por plan, leídos de la BD y de Access (no escritos a fuego en el texto).
$htmlQuotas = ['Plan gratuito: ' . Access::FREE_MONTHLY_HTML_UPLOADS . ' por mes'];
foreach (Access::tiers() as $t) {
    $htmlQuotas[] = e((string) $t['name']) . ': ' . (int) $t['html_uploads_per_month'] . ' por mes';
}
$htmlQuotasHtml = '<ul><li>' . implode('</li><li>', $htmlQuotas) . '</li></ul>';

// Programa de creadores y premios: todos los números salen de la configuración vigente (Creators::config / Awards::config).
$cc = Creators::config();
$ac = Awards::config();
$usd = static fn (int $cents): string => '$' . number_format($cents / 100, 2, '.', ',');
$aliasCost = Ranking::aliasChangeCost();
$topTierName = '';
foreach (Access::tiers() as $t) {
    if ($t['slug'] === $cc['top_tier_slug']) {
        $topTierName = (string) $t['name'];
    }
}
$creatorMilestones = '<ul>';
foreach ($cc['milestones'] as $m) {
    $badge = $m['badge'] !== '' ? ' y la insignia «' . e(Awards::BADGES[$m['badge']][0]) . '»' : '';
    $creatorMilestones .= '<li>' . (int) $m['count'] . ' plantilla' . ($m['count'] === 1 ? '' : 's') . ' aprobada' . ($m['count'] === 1 ? '' : 's') . ': ' . (int) $m['coins'] . ' monedas' . $badge . '.</li>';
}
$creatorMilestones .= '</ul>';
$topPlaces = '<ul>';
foreach ($ac['month_coins'] as $i => $c) {
    $topPlaces .= '<li>Puesto ' . ($i + 1) . ': ' . (int) $c . ' monedas.</li>';
}
$topPlaces .= '</ul>';
$donorMilestones = '<ul>';
foreach ($ac['milestones'] as $m) {
    $donorMilestones .= '<li>Apoyo acumulado de ' . e($usd((int) $m['cents'])) . ': ' . (int) $m['coins'] . ' monedas.</li>';
}
$donorMilestones .= '</ul>';
$monthlyTop = $cc['top_tier_slug'] !== '' && (int) $cc['top_tier_days'] > 0 && $topTierName !== ''
    ? ' La persona n.º 1 del mes recibe además una <strong>mejora temporal de plan</strong>: ' . (int) $cc['top_tier_days'] . ' días del plan «' . e($topTierName) . '», que se suma a tu plan (si ya tienes uno superior, no se reemplaza ni se pierde) y no es renovable ni comprable.'
    : '';
$quotaShare = $cc['share_on_quota_unlock']
    ? ' y, cuando la plantilla se usa por el cupo mensual de una membresía, sobre su <strong>valor nominal en monedas</strong> (esa parte la financia LovePages)'
    : '';

$sections = [
    'aceptacion' => ['Aceptación de los términos', "
        <p>Estos términos regulan el uso de LovePages, un servicio de <strong>$who</strong> disponible en <strong>pdsx.org/love</strong>. Al crear una cuenta, comprar o usar el servicio declaras que los has leído y que los aceptas. Si no estás de acuerdo, no uses la plataforma.</p>"],

    'servicio' => ['Naturaleza del servicio', '
        <p>LovePages es una plataforma <em>SaaS</em> que permite crear páginas web personalizadas para parejas, a partir de plantillas estáticas e interactivas, y compartirlas mediante un enlace público. El servicio se ofrece mediante:</p>
        <ul>
          <li>un <strong>plan gratuito</strong> y <strong>membresías con vigencia limitada y sin renovación automática</strong> (Romántico, Pareja y Eterno). El plan gratuito permite crear hasta ' . Access::FREE_MONTHLY_PAGES . ' páginas por mes calendario (hora de El Salvador); las membresías amplían las páginas activas, su duración y los beneficios;</li>
          <li><strong>monedas virtuales</strong>, que se consumen al crear y renovar páginas, y <strong>paquetes de recarga</strong> de monedas;</li>
          <li>compra suelta de algunas plantillas.</li>
        </ul>
        <p>Los pagos se procesan a través de la pasarela <strong>Wompi El Salvador</strong>.</p>'],

    'cuenta' => ['Tu cuenta', '
        <p>Debes ser mayor de edad o contar con la autorización de tu madre, padre o tutor. Los datos que das al registrarte deben ser verdaderos. Eres responsable de la confidencialidad de tu contraseña y de lo que ocurra en tu cuenta; avísanos si sospechas un uso no autorizado. Puedes cambiar tu correo y tu contraseña desde <a href="' . e(url('profile.php')) . '">tu perfil</a>.</p>'],

    'precios' => ['Membresías, monedas y precios', '
        <ul>
          <li>Los precios se muestran en dólares estadounidenses (USD) en la <a href="' . e(url('tienda.php')) . '">Tienda</a>. Cada membresía tiene una <strong>vigencia limitada</strong> (ver «Vigencia de las membresías»), <strong>no se renueva automáticamente</strong> y no genera cobros recurrentes: cada pago es independiente.</li>
          <li>Las monedas <strong>no vencen</strong> con la membresía. El bono de monedas de tu plan se abona en cada compra o renovación.</li>
          <li>Las <strong>monedas son virtuales</strong>: no son dinero, no generan intereses, no son transferibles entre cuentas ni canjeables por dinero. Su único uso es dentro de LovePages.</li>
          <li>Los bonos y las condiciones de cada paquete o plan son los publicados en la Tienda en el momento de la compra; pueden cambiar hacia el futuro sin afectar lo ya adquirido.</li>
          <li>Los códigos promocionales tienen sus propias condiciones (vigencia, usos máximos) y no son acumulables salvo que se indique.</li>
        </ul>'],

    'pagos' => ['Pagos con Wompi', '
        <p>Al pagar te redirigimos al entorno seguro de Wompi. Nosotros no recibimos ni almacenamos los datos de tu tarjeta. Las monedas o la membresía se acreditan cuando Wompi nos confirma el pago aprobado; mientras tanto el pago figura como pendiente. Las fallas, demoras, rechazos o comisiones de Wompi o de tu entidad financiera son ajenas a nosotros.</p>'],

    'membresias-vigencia' => ['Vigencia de las membresías', "
        <p>Cada membresía dura desde su compra:</p>
        $periods
        <ul>
          <li>No hay renovación automática ni cobros recurrentes. Para conservar tus beneficios debes <strong>renovar antes del vencimiento</strong>: comprar el mismo plan suma otro período a la fecha de vencimiento.</li>
          <li>Al vencer, vuelves al <strong>plan gratuito</strong> (cupo mensual de páginas, con anuncios y sin los demás beneficios del plan).</li>
          <li>Tus páginas ya creadas conservan su propia vigencia, aunque tu membresía venza.</li>
          <li>Tus monedas no se pierden al vencer la membresía.</li>
          <li>La política de no reembolso aplica también a las renovaciones.</li>
        </ul>"],

    'vigencia' => ['Vigencia y caducidad de las páginas', "
        <p><strong>Importante:</strong> las páginas tienen una vigencia limitada, que depende del plan que tengas al crearla o renovarla:</p>
        $durations
        <p>En el <strong>plan gratuito</strong> puedes crear hasta " . Access::FREE_MONTHLY_PAGES . " páginas por mes calendario (hora de El Salvador) y cada una dura " . Access::FREE_SITE_DAYS . " días. El cupo se reinicia el día 1 de cada mes; las páginas eliminadas o vencidas <strong>no devuelven cupo</strong> y renovar una página vencida usa un cupo del mes. Las membresías de pago amplían las páginas activas y su duración.</p>
        <p>Al vencer, la página <strong>caduca</strong>: su enlace deja de mostrar el contenido y responde con el error 410 (<em>Gone</em>). Para volver a publicarla debes <strong>renovarla</strong> con monedas desde <a href=\"" . e(url('dashboard.php')) . "\">Mis páginas</a>, siempre que tu plan lo permita (cupo mensual en el plan gratuito o límite de páginas activas en los de pago).</p>
        <p><strong>No garantizamos la conservación de los datos de las páginas vencidas</strong> que no se renueven: podemos eliminarlas, sin aviso previo, en cualquier momento. Si quieres conservar una página, renuévala a tiempo o descarga su HTML antes de que venza.</p>"],

    'reembolsos' => ['Política de no reembolso', '
        <p>LovePages entrega servicios digitales de consumo inmediato: las monedas y las membresías se asignan en el acto y las páginas se publican al crearlas. Por eso, <strong>los pagos procesados a través de Wompi son definitivos y no reembolsables</strong>, y las monedas ya acreditadas o gastadas no se devuelven.</p>
        <p>Se exceptúan los casos en que la ley aplicable lo exija, un cobro duplicado o un pago aprobado cuyo servicio no se haya acreditado por una falla atribuible a nosotros. En esos casos escríbenos a ' . $contact . ' con la referencia del pago.</p>'],

    'contenido' => ['Contenido del usuario y conducta prohibida', '
        <p>Tú eres el único responsable del contenido que publicas en tus páginas. Está <strong>estrictamente prohibido</strong> usar LovePages para publicar o difundir:</p>
        <ul>
          <li>contenido ofensivo, discriminatorio, difamatorio, obsceno o violento;</li>
          <li>acoso, amenazas, suplantación de identidad o cualquier conducta dirigida a dañar a otra persona;</li>
          <li>material ilegal o que incite a cometer delitos, incluido cualquier contenido que involucre a menores;</li>
          <li>contenido que infrinja derechos de autor, marcas u otros derechos de propiedad intelectual;</li>
          <li>información personal o sensible de terceros (teléfonos, direcciones, documentos, fotografías íntimas, etc.) sin su consentimiento;</li>
          <li>malware, phishing, spam o intentos de vulnerar la plataforma.</li>
        </ul>
        <p>Al publicar contenido nos otorgas una licencia limitada, no exclusiva y sin costo para alojarlo y mostrarlo únicamente con el fin de prestarte el servicio. Conservas todos los derechos sobre lo que escribes.</p>'],

    'html-propio' => ['HTML propio y fotos de los usuarios', "
        <p>Puedes subir tu propio archivo <strong>.html</strong> (o un <strong>.zip</strong> con HTML y recursos) para publicarlo como página de LovePages, y subir fotos a las plantillas que lo permiten. Al hacerlo aceptas estas reglas:</p>
        <ul>
          <li><strong>Eres responsable</strong> de tu HTML, tus fotos y todos sus recursos, y declaras tener los derechos para usarlos.</li>
          <li><strong>Está prohibido</strong> subir malware, phishing, mineros de criptomonedas, suplantación de identidad o de marcas, contenido ilegal u ofensivo, y cualquier intento de evadir el aislamiento técnico o de acceder a datos de otras personas o de la plataforma.</li>
          <li><strong>El escaneo es automático</strong> y no garantiza que el contenido sea seguro ni lícito; que un archivo lo supere no significa que lo aprobemos.</li>
          <li>LovePages puede <strong>eliminar la página o suspender la cuenta sin aviso</strong> si detecta o le reportan un incumplimiento. No hay reembolso ni devolución de monedas.</li>
          <li><strong>Aislamiento:</strong> tu HTML se sirve en un espacio aislado, sin acceso a tu sesión, cookies ni datos de tu cuenta. Por ese aislamiento, los formularios, las ventanas emergentes, las descargas y la navegación fuera de la página no funcionan.</li>
          <li><strong>Cupo mensual de subidas de HTML propio</strong> (mes calendario, hora de El Salvador):$htmlQuotasHtml La página creada también cuenta en el límite de páginas de tu plan y tiene su misma vigencia. Subir una versión nueva cuenta como una subida.</li>
          <li>El HTML y sus recursos <strong>se eliminan al borrar la página o la cuenta</strong>.</li>
          <li>Guardamos, por cada subida, el <strong>hash (huella) y el tamaño del archivo y la fecha</strong>, para controlar el cupo y prevenir abusos.</li>
        </ul>
        <p><strong>Plantillas privadas y publicación opcional.</strong></p>
        <ul>
          <li>Las plantillas (HTML propio) que subes son <strong>privadas por defecto</strong>: solo tú las usas para crear tus páginas; no aparecen en la Galería ni las usa nadie más.</li>
          <li>Puedes <strong>usarlas y modificarlas</strong> cuantas veces quieras (subir una versión nueva cuenta dentro de tu cupo mensual de subidas de HTML propio).</li>
          <li>Si tú así lo consideras, puedes decidir <strong>publicarlas después en la Galería</strong> para que otros usuarios las usen. La publicación <strong>nunca es automática</strong>: solo ocurre con tu solicitud o autorización expresa (desde tu cuenta, con la opción «Publicar en la Galería»). Para publicarla, LovePages podrá revisarla y <strong>adaptarla o modificarla</strong> (por ejemplo, ajustar el diseño móvil, sustituir datos personales por campos editables o corregir problemas de seguridad), y podrá rechazarla si no cumple las reglas.</li>
          <li>Al autorizar la publicación otorgas a LovePages una <strong>licencia no exclusiva y limitada</strong> para alojar, mostrar, adaptar y ofrecer esa plantilla a otros usuarios mientras esté publicada; declaras tener los derechos necesarios y que no incluye datos personales ni contenido de terceros. Conservas la titularidad de tu plantilla.</li>
          <li>Puedes pedir que se <strong>retire del catálogo</strong> cuando quieras; las páginas que otros usuarios ya hayan creado con ella siguen vigentes hasta su vencimiento. Las condiciones específicas de una publicación (por ejemplo, crédito de autoría) se te informarán antes y solo se aplican si las aceptas.</li>
        </ul>"],

    'plantillas-privadas-publicas' => ['Plantillas privadas y públicas', "
        <p>Hay dos formas distintas de usar tu propio HTML, con cupos separados:</p>
        <ul>
          <li><strong>Privadas (HTML propio):</strong> solo las usas tú para tus páginas y <strong>no se publican</strong> salvo que tú decidas enviarlas por «Publicar en la Galería». Están sujetas al cupo mensual de subidas según tu plan (mes calendario, hora de El Salvador):$htmlQuotasHtml</li>
          <li><strong>Públicas (Galería):</strong> son plantillas que envías para que otras personas las usen. <strong>No consumen</strong> el cupo mensual de HTML propio, ni el HTML propio privado cuenta contra ellas. Pasan por <strong>revisión previa</strong> y solo son visibles si un administrador las aprueba. Límites: hasta " . (int) $cc['max_pending'] . " envíos pendientes a la vez y hasta " . (int) $cc['max_templates'] . " plantillas públicas por usuario.</li>
        </ul>"],

    'creadores' => ['Programa de creadores', "
        <p><strong>Quién puede participar.</strong> Cualquier usuario con cuenta activa (no suspendida) y un alias público. Participar es voluntario y gratuito.</p>
        <p><strong>Revisión y aprobación previa.</strong> Toda plantilla enviada queda <em>pendiente</em> y no aparece en la Galería hasta que un administrador la apruebe; puede ser rechazada (con una nota) o retirada después. Estados: pendiente, aprobada, rechazada y retirada. Podemos ajustar su precio, categoría y su inclusión en el cupo de membresías al aprobarla. El precio propuesto va de " . (int) $cc['min_price'] . " a " . (int) $cc['max_price'] . " monedas.</p>
        <p><strong>Originalidad y derechos.</strong> Declaras ser autor o tener derechos sobre la plantilla, y que no contiene datos personales ni contenido de terceros. Rigen las mismas prohibiciones del apartado «HTML propio y fotos de los usuarios» (malware, phishing, suplantación, contenido ilegal u ofensivo, evasión del aislamiento técnico). La plantilla debe usar los campos de la plataforma (nombres, fecha, mensaje) y se sirve siempre en un espacio aislado.</p>
        <p><strong>Licencia.</strong> Nos otorgas una licencia limitada, no exclusiva y sin costo para alojar, mostrar y adaptar tu plantilla y ofrecerla a otros usuarios mientras esté publicada. Conservas su titularidad.</p>
        <p><strong>Rechazar, retirar o eliminar.</strong> Podemos rechazar, retirar o eliminar cualquier plantilla, y suspender la participación, si incumple estas reglas o la ley, sin previo aviso y sin que ello genere indemnización.</p>
        <p><strong>Reparto de ingresos.</strong> Cuando <em>otra persona</em> crea o renueva una página con tu plantilla aprobada, recibes el <strong>" . (int) $cc['share_pct'] . " %</strong> de las monedas gastadas en ese uso" . $quotaShare . ". Se acredita en <strong>monedas</strong> en tu saldo (se liquida automáticamente tras cada uso y también al abrir tu panel). Reglas:</p>
        <ul>
          <li>Las monedas ganadas <strong>no son dinero</strong>, no son canjeables por dinero, no son transferibles y solo sirven dentro de LovePages.</li>
          <li>No hay comisión por <strong>autocompras</strong>: usar tu propia plantilla no genera ganancias.</li>
          <li>Podemos modificar el porcentaje <strong>hacia el futuro</strong>, avisando en el sitio; no afecta a lo ya generado.</li>
          <li>Las ganancias pueden <strong>anularse</strong> si detectamos fraude, abuso o manipulación (por ejemplo, cuentas creadas para usar tus plantillas).</li>
        </ul>
        <p><strong>Autoría visible.</strong> Tu plantilla se muestra con tu <strong>alias público</strong> como autor («Por tu alias») y puedes aparecer en «Colaboradores destacados» (una plantilla es exitosa con " . (int) $cc['success_uses'] . " usos o más por otras personas). Nunca mostramos tu correo. Cambiar tu alias puede costar monedas (ver «Rankings y premios»).</p>
        <p><strong>Retirada.</strong> Puedes retirar tu plantilla del catálogo cuando quieras desde «Mis plantillas públicas». Las <strong>páginas que otras personas ya crearon</strong> con ella siguen vigentes hasta su vencimiento (y pueden renovarse), pero no se ofrece a nuevos usuarios ni generan nuevas ganancias. Para reenviarla necesitas un archivo nuevo, que se revisa de nuevo.</p>"],

    'rankings-premios' => ['Rankings y premios', "
        <p><strong>Top de donadores.</strong> Se ordena por el <em>apoyo</em> acumulado: cuentan los <strong>pagos reales cumplidos</strong> (aprobados por Wompi o registrados manualmente); no cuentan cupones, cortesías ni pagos anulados. Hay ranking del <strong>mes calendario (hora de El Salvador)</strong> e histórico; en caso de empate gana quien tenga el primer pago cumplido más antiguo del periodo. Solo se muestra tu <strong>alias</strong> si activas la opción; si no, apareces como «Donador anónimo». Puedes ocultarlo cuando quieras y es gratis. Cambiar un alias existente cuesta <strong>" . (int) $aliasCost . " monedas</strong> (no reembolsables; cambiar solo mayúsculas o tildes es gratis; el primer alias es gratis). Los alias deben ser únicos, no pueden suplantar a la plataforma o su equipo ni parecer enlaces, y podemos quitar un alias abusivo.</p>
        <p><strong>Premios del mes (donadores).</strong> Al cerrar cada mes, los primeros " . (int) $ac['top_n'] . " puestos con al menos " . e($usd((int) $ac['min_month_cents'])) . " de apoyo en el mes reciben monedas e insignia:</p>
        $topPlaces
        <p><strong>Hitos de apoyo acumulado</strong> (una sola vez cada uno):</p>
        $donorMilestones
        <p><strong>Premios a creadores.</strong> Hitos por plantillas aprobadas (una sola vez cada uno):</p>
        $creatorMilestones
        <p>Cierre mensual de creadores: quienes tengan al menos " . (int) $cc['month_min_templates'] . " plantillas aprobadas y " . (int) $cc['month_min_uses'] . " usos por otras personas en el mes reciben " . (int) $cc['month_coins'] . " monedas y la insignia «Colaborador destacado del mes».$monthlyTop</p>
        <ul>
          <li>Los premios son <strong>monedas o beneficios temporales sin valor monetario</strong>; no son canjeables por dinero ni transferibles.</li>
          <li>Se otorgan automáticamente y una sola vez por concepto. Los premios de creadores rigen desde el lanzamiento del programa (no son retroactivos).</li>
          <li>LovePages puede <strong>modificar o cancelar</strong> los rankings y premios hacia el futuro, y <strong>anular premios</strong> obtenidos con fraude o abuso.</li>
        </ul>"],

    'moderacion' => ['Moderación, suspensión y eliminación', '
        <p>Nos reservamos el derecho de <strong>eliminar páginas, suspender o cancelar cuentas</strong> que incumplan estos términos o la ley, <strong>sin previo aviso</strong> y sin derecho a reembolso ni a la devolución de monedas. También podemos cooperar con las autoridades competentes cuando corresponda.</p>'],

    'propiedad' => ['Propiedad intelectual', '
        <p>La plataforma, sus plantillas, diseños, ilustraciones, marca y código son de <strong>' . $who . '</strong> o de sus licenciantes. Tu compra te da derecho a usar las plantillas para crear tus páginas dentro de LovePages, no a copiarlas, revenderlas ni redistribuirlas. El HTML que descargas de tus propias páginas es para tu uso personal.</p>'],

    'disponibilidad' => ['Disponibilidad y limitación de responsabilidad', '
        <p>Ofrecemos el servicio «tal cual» y «según disponibilidad». No garantizamos que funcione sin interrupciones ni errores. En la medida permitida por la ley, <strong>no nos hacemos responsables</strong> por:</p>
        <ul>
          <li>interrupciones, mantenimientos o fallas del servicio o de infraestructura de terceros;</li>
          <li>fallas, demoras o indisponibilidad de la pasarela de pagos <strong>Wompi</strong> o de otros proveedores;</li>
          <li>pérdida de datos o de páginas por causas de <strong>fuerza mayor</strong> o caso fortuito, ataques informáticos o hechos fuera de nuestro control razonable;</li>
          <li>el contenido publicado por los usuarios ni los daños derivados de su uso;</li>
          <li>daños indirectos, lucro cesante o pérdidas de oportunidad.</li>
        </ul>
        <p>Cuando la ley aplicable no permita excluir una responsabilidad, esta se limita al monto que hayas pagado por el servicio afectado. Te recomendamos conservar copia de lo que te importe (por ejemplo, descargando el HTML de tus páginas).</p>'],

    'privacidad' => ['Privacidad', '
        <p>Tratamos tus datos conforme a nuestra <a href="' . e(url('privacy.php')) . '">Política de privacidad</a>, que forma parte de estos términos.</p>'],

    'cambios' => ['Cambios en los términos', '
        <p>Podemos modificar estos términos para reflejar cambios legales o del servicio. Publicaremos la versión vigente aquí con su fecha; si el cambio es relevante lo avisaremos en el sitio. Usar el servicio después de la publicación implica que aceptas los nuevos términos. Lo ya pagado no cambia.</p>'],

    'ley' => ['Ley aplicable y jurisdicción', '
        <p>Estos términos se rigen por las leyes de la República de El Salvador. Cualquier controversia se someterá a los tribunales competentes de El Salvador, sin perjuicio de los derechos irrenunciables que la ley reconozca a los consumidores.</p>'],

    'contacto' => ['Soporte y contacto', "
        <p>$who · pdsx.org/love · $contact</p>"],
];

legal_page(
    'Términos y condiciones',
    'Lee estas reglas antes de usar LovePages. Son breves y explican cómo funcionan las membresías, las monedas, la caducidad de las páginas y los pagos.',
    $sections,
    ['Ver Política de privacidad', 'privacy.php'],
);
