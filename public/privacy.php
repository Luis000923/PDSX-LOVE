<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/legal.php';

/** Política de privacidad de LovePages. Debe reflejar lo que la app realmente guarda (ver database/schema.sql). */
$who = e(legal_entity());
$contact = legal_contact_html();

// Números leídos de la configuración vigente (no a fuego).
$aliasCost = Ranking::aliasChangeCost();

$sections = [
    'responsable' => ['Quién es el responsable', "
        <p><strong>$who</strong> («nosotros») opera LovePages en <strong>pdsx.org/love</strong>, una plataforma para crear páginas web personalizadas para parejas. Esta política explica qué datos personales tratamos, para qué y qué derechos tienes.</p>
        <p>Para cualquier consulta sobre privacidad o para ejercer tus derechos escribe a $contact.</p>"],

    'datos' => ['Qué datos recopilamos', '
        <p>Solo guardamos lo necesario para prestar el servicio:</p>
        <ul>
          <li><strong>Cuenta:</strong> tu correo electrónico y tu contraseña, que se almacena únicamente en forma de <em>hash</em> (nunca en texto legible), además de la fecha de registro.</li>
          <li><strong>Monedas y membresía:</strong> tu plan, tu saldo de monedas virtuales y el registro de movimientos (bonos, recargas, creación y renovación de páginas).</li>
          <li><strong>Pagos:</strong> por cada pago, la referencia interna, el monto, la moneda, el estado, el identificador de la transacción en Wompi y el código promocional usado, si lo hubo. <strong>No almacenamos números de tarjeta ni datos bancarios</strong>: los introduces en el entorno de Wompi.</li>
          <li><strong>Páginas de pareja:</strong> el contenido que escribes (tu nombre, el de tu pareja, la fecha de inicio de la relación y tu mensaje), la plantilla elegida, el enlace público y las fechas de creación y vencimiento.</li>
          <li><strong>Datos técnicos:</strong> tu dirección IP en los intentos de inicio de sesión (para limitar abusos) y una cookie de sesión.</li>
        </ul>
        <p>No pedimos datos sensibles. Te pedimos que tampoco incluyas en tus páginas información sensible de terceros (ver los <a href="' . e(url('terms.php#contenido')) . '">Términos y condiciones</a>).</p>'],

    'finalidad' => ['Para qué usamos tus datos', '
        <p>Usamos tus datos exclusivamente para:</p>
        <ul>
          <li>crear y administrar tu cuenta y autenticarte;</li>
          <li>procesar pagos, acreditar monedas y membresías y mantener el historial de transacciones;</li>
          <li>alojar, mostrar y renovar tus páginas de pareja;</li>
          <li>proteger el servicio (límite de intentos de acceso, prevención de fraude y abuso) y cumplir obligaciones legales.</li>
        </ul>
        <p>No vendemos tus datos ni los usamos para perfilar tu comportamiento.</p>'],

    'base-legal' => ['Base legal del tratamiento', '
        <p>Tratamos tus datos porque son necesarios para ejecutar el servicio que solicitas al registrarte y comprar, porque nos das tu consentimiento al crear la cuenta, para cumplir obligaciones legales aplicables y por nuestro interés legítimo en mantener la seguridad de la plataforma.</p>'],

    'terceros' => ['Con quién compartimos los datos', '
        <ul>
          <li><strong>Wompi El Salvador</strong> (pasarela de pagos): recibe los datos necesarios para cobrar y confirma el resultado del pago. Sus condiciones y su política de privacidad rigen el tratamiento que hace de tus datos de pago.</li>
          <li><strong>Proveedor de alojamiento e infraestructura</strong>: almacena la base de datos y los archivos del sitio por cuenta nuestra.</li>
          <li><strong>Recursos de terceros en el navegador</strong>: el sitio carga tipografías de Google Fonts y estilos desde una red de distribución de contenidos (CDN), por lo que esos proveedores pueden recibir tu dirección IP y datos técnicos del navegador al cargar la página.</li>
          <li><strong>Publicidad</strong>: las páginas públicas de cuentas sin plan libre de anuncios pueden mostrar un banner publicitario.</li>
        </ul>
        <p>Podemos revelar información si una autoridad competente lo exige conforme a la ley. Tus páginas son accesibles para cualquiera que tenga su enlace; no aparecen en buscadores (se marcan con <em>noindex</em>), pero compartir el enlace es decisión tuya.</p>
        <p>Algunos de estos proveedores pueden tratar datos fuera de El Salvador; en ese caso aplican las salvaguardas contractuales de cada proveedor.</p>'],

    'html-propio' => ['HTML propio y fotos que subes', '
        <p>Si subes un archivo HTML (o un .zip) o fotos, guardamos ese contenido para servirte tu página, y por cada subida de HTML propio registramos el <strong>hash (huella) y el tamaño del archivo y la fecha</strong> para controlar el cupo mensual y prevenir abusos. Un análisis automático revisa el archivo al subirlo.</p>
        <ul>
          <li>Tu HTML se sirve <strong>aislado</strong>: no tiene acceso a tu sesión, cookies ni datos de tu cuenta.</li>
          <li>El HTML y sus recursos se eliminan al borrar la página o la cuenta; el registro de subidas (hash, tamaño, fecha) se elimina con la cuenta.</li>
          <li>Tus plantillas subidas son <strong>privadas</strong>: mientras lo sean, solo se usan para servirte tus páginas.</li>
          <li>Si <strong>autorizas su publicación</strong> con el botón «Publicar en la Galería» de esa página, el contenido de esa plantilla pasa a ser visible para otros usuarios; por eso <strong>no debe contener datos personales</strong> ni contenido de terceros. Ver <a href="' . e(url('terms.php#html-propio')) . '">HTML propio y fotos de los usuarios</a>.</li>
        </ul>'],

    'programas' => ['Rankings, premios y programa de creadores', "
        <p>Para el <strong>Top de donadores</strong>, los <strong>premios</strong> y el <strong>programa de creadores</strong> (ver <a href=\"" . e(url('terms.php#rankings-premios')) . "\">Rankings y premios</a> y <a href=\"" . e(url('terms.php#creadores')) . "\">Programa de creadores</a> en los Términos) tratamos estos datos:</p>
        <ul>
          <li><strong>Alias público</strong> (que eliges al registrarte o después, único por cuenta): se muestra en el Top de donadores <em>solo si activas esa opción</em> y, si publicas plantillas, como <strong>autor</strong> de las mismas y en «Colaboradores destacados». Nunca mostramos tu correo.</li>
          <li><strong>Total de apoyo agregado</strong> (suma de tus pagos reales cumplidos por periodo) para calcular los rankings y los hitos; no se publica el detalle de tus pagos.</li>
          <li><strong>Libro de ganancias de creador</strong> (qué plantilla se usó, cuántas monedas correspondieron y su estado) y el libro de movimientos de monedas.</li>
          <li><strong>Insignias y concesiones de premios</strong> (monedas o mejoras temporales de plan otorgadas), con su fecha, para no otorgarlas dos veces.</li>
          <li><strong>Plantillas que envías</strong> (archivo, miniatura, descripción, precio propuesto, estado de revisión y nota del revisor).</li>
        </ul>
        <p><strong>Base y finalidad:</strong> ejecutar el programa que solicitas al participar, mantener la integridad de los rankings y los premios y prevenir fraude. <strong>Solo se publica lo que tú activas o autorizas</strong> (mostrar tu alias en el ranking, publicar una plantilla con tu alias como autor).</p>
        <p><strong>Retención:</strong> mientras tu cuenta esté activa y, para el libro de ganancias y premios, el tiempo necesario para evitar duplicados y fraudes; se eliminan con la cuenta.</p>
        <p><strong>Cómo revocar o eliminar:</strong> puedes <strong>ocultar tu alias del ranking</strong> cuando quieras (es gratis) desde <a href=\"" . e(url('profile.php#alias')) . "\">tu perfil</a>; cambiar un alias existente cuesta " . (int) $aliasCost . " monedas. Puedes <strong>retirar tus plantillas</strong> del catálogo desde «Mis plantillas públicas» (las páginas ya creadas por otras personas siguen hasta su vencimiento). Para eliminar tu alias, tus plantillas o tu cuenta escribe a $contact.</p>"],

    'conservacion' => ['Cuánto tiempo los conservamos', '
        <ul>
          <li><strong>Cuenta y registro de pagos:</strong> mientras la cuenta esté activa y durante el tiempo que exijan las obligaciones legales, contables y de prevención de fraude.</li>
          <li><strong>Páginas de pareja:</strong> mientras estén vigentes. Al vencer dejan de ser accesibles y, si no se renuevan, podemos eliminarlas sin aviso previo (ver <a href="' . e(url('terms.php#vigencia')) . '">Vigencia de las páginas</a>).</li>
          <li><strong>Intentos de inicio de sesión (IP):</strong> el tiempo mínimo necesario para limitar abusos.</li>
        </ul>
        <p>Al eliminar tu cuenta se eliminan también tus páginas y los datos asociados, salvo lo que debamos conservar por ley.</p>'],

    'derechos' => ['Tus derechos y cómo ejercerlos', '
        <p>Puedes solicitar en cualquier momento:</p>
        <ul>
          <li><strong>Acceso</strong> a los datos personales que tenemos sobre ti;</li>
          <li><strong>Rectificación</strong> de datos inexactos (además, puedes cambiar tu correo y tu contraseña desde <a href="' . e(url('profile.php')) . '">tu perfil</a>);</li>
          <li><strong>Eliminación de tu cuenta y de los datos asociados</strong>;</li>
          <li><strong>Oposición o limitación</strong> del tratamiento cuando proceda.</li>
        </ul>
        <p>Envía tu solicitud desde el correo de tu cuenta a ' . $contact . '. Verificaremos tu identidad y responderemos en un plazo razonable. Si consideras que no hemos atendido tus derechos, puedes acudir a la autoridad competente de El Salvador.</p>'],

    'cookies' => ['Cookies y sesión', '
        <p>Usamos una única cookie esencial de sesión (<code>lp_sid</code>) para mantenerte identificado y proteger los formularios contra ataques CSRF. Es de sesión (se borra al cerrar el navegador), <em>HttpOnly</em>, <em>SameSite=Lax</em> y, en producción, solo viaja por HTTPS. No usamos cookies de analítica ni de seguimiento propias.</p>'],

    'seguridad' => ['Seguridad', '
        <ul>
          <li>Conexiones cifradas (HTTPS) en producción.</li>
          <li>Contraseñas almacenadas con un algoritmo de <em>hash</em> seguro y con sal; nadie, incluido nuestro equipo, puede leerlas.</li>
          <li>Protección de formularios (CSRF), política de seguridad de contenidos (CSP) y límite de intentos de acceso.</li>
          <li>Pagos procesados por Wompi, una pasarela que declara operar bajo estándares de seguridad de la industria de pagos; no tocamos tus datos de tarjeta.</li>
        </ul>
        <p>Ningún sistema es infalible. Si detectamos una brecha que afecte tus datos, actuaremos y te informaremos conforme a la ley.</p>'],

    'menores' => ['Menores de edad', '
        <p>LovePages no está dirigido a menores de edad. Si eres menor, necesitas la autorización de tu madre, padre o tutor para usar el servicio. Si detectamos datos de un menor sin autorización, los eliminaremos.</p>'],

    'cambios' => ['Cambios en esta política', '
        <p>Podemos actualizar esta política; publicaremos la nueva versión aquí con su fecha. Si el cambio es relevante, lo avisaremos en el sitio. Seguir usando el servicio después implica que aceptas la versión vigente.</p>'],

    'contacto' => ['Soporte y contacto', "
        <p>$who · pdsx.org/love · $contact</p>"],
];

legal_page(
    'Política de privacidad',
    'Tu privacidad importa. Aquí te explicamos, en lenguaje claro, cómo cuidamos los datos de tu cuenta y de tus páginas.',
    $sections,
    ['Ver Términos y condiciones', 'terms.php'],
);
