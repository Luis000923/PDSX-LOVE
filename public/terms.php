<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/legal.php';

/** Términos y condiciones de uso de LovePages. Las duraciones por plan se leen de la BD (ver legal_plan_durations_html). */
$who = e(legal_entity());
$contact = legal_contact_html();
$durations = legal_plan_durations_html();
$periods = legal_plan_periods_html();

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
