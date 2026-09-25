# HTML propio de los usuarios

Cualquier plan (incluido el gratuito) puede subir su propio `.html` (o un `.zip` con HTML y recursos). Se escanea, se publica
directo (sin aprobación previa) y se sirve AISLADO. Moderación posterior por admin.

## Archivos
- `src/HtmlScanner.php`: escáner (HTML, CSS, JS, SVG). `src/UserHtml.php`: validación de la subida, ZIP seguro, almacenamiento.
- `public/upload_html.php`: pantalla y POST. `public/frame.php`: documento aislado. `public/view.php` (rama `kind='user'`): envoltorio.
- Datos: plantilla oculta `templates.slug='html-propio'` (`kind='user'`), `user_html_sites`, `html_uploads` (libro de cupo).

## Aislamiento (la barrera real)
1. `/c/{slug}` (view.php) es una página NUESTRA con la CSP de bootstrap y sin scripts; solo contiene
   `<iframe src="/c/{slug}/f" sandbox="allow-scripts" referrerpolicy="no-referrer">` (sin `allow-same-origin`), el banner del plan
   gratuito y el enlace «Reportar esta página».
2. `/c/{slug}/f` (frame.php, sin sesión ni cookies) responde el documento con su propia CSP, que sustituye a la de bootstrap:
   `sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' cdn.tailwindcss.com cdn.jsdelivr.net cdnjs.cloudflare.com 'self'; style-src 'unsafe-inline' 'self' fonts.googleapis.com; font-src 'self' data: fonts.gstatic.com; img-src 'self' data: https:; media-src 'self' data:; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'`
   más `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`.
   Como `sandbox` viaja en la cabecera, el documento tiene origen opaco aunque alguien abra `/c/{slug}/f` directamente.
3. El HTML vive fuera del webroot (`storage/user_html/{slug}/index.html`); solo frame.php lo lee. Los recursos del ZIP van a
   `public/uploads/sites/{slug}/a/` (Apache: sin ejecución, sin .html/.php, `nosniff`, CSP `sandbox`, `Access-Control-Allow-Origin: *`
   para que un documento de origen opaco pueda cargar fuentes web).
4. Caducada = 410, inexistente/suspendida/no-`user` = 404 (mismo criterio que view.php). Slugs con `/D`.

## Escáner (defensa en profundidad)
Rechaza (mensajes en español, sin reflejar contenido): `iframe/frame/frameset/object/embed/applet/form/base/isindex`, `meta http-equiv`
(refresh, set-cookie, CSP), `link rel` import/prefetch/preload/dns-prefetch/preconnect/stylesheet a hosts no permitidos, `script src` fuera de
la lista blanca (o ruta relativa del ZIP), URLs `javascript:/vbscript:/blob:/data:` no multimedia, `srcdoc`, SVG con script/`on*`/`foreignObject`/`use`
externo/animaciones que cambian `href`, y en JS: `eval`, `Function`, `setTimeout("...")`, `document.write/cookie`, storage, `fetch/XHR/WebSocket/
EventSource/sendBeacon`, workers, WebAssembly, `import()`, `window.open`, `location`, `top/parent/opener/frames`, `postMessage`, APIs del dispositivo,
`constructor`, `atob/fromCharCode`, ofuscación (>20 escapes `\x/\u`, base64/hex largos), mineros, y phishing (campo contraseña; texto de
credenciales/pago junto a inputs). CSS: `expression(`, `behavior:`, `-moz-binding`, `@import` externo, `url(javascript:)`, `url(data:)` no imagen/fuente.
Técnica: DOMDocument (`LIBXML_NONET`, nunca `NOENT`) para recorrer etiquetas/atributos; JS normalizado (escapes decodificados, comentarios de bloque
fuera, espacios alrededor de `.`); comprobación también sobre el texto crudo y sobre el texto sin comentarios (comentarios condicionales).
Tope 512 KB por documento.
Límites conocidos: no es un análisis de flujo; concatenaciones de cadenas pueden evadirlo (por eso existe el sandbox). Imágenes `https:` externas
permitidas por la CSP (posible pixel de rastreo). Un `<link rel=stylesheet>` a jsDelivr/cdnjs pasa el escáner pero la CSP lo bloquea (solo
`fonts.googleapis.com` y `'self'` para estilos). Con `<base>` (solo si hay recursos), los enlaces `#ancla` se resuelven contra la carpeta de recursos.
Por el sandbox (sin allow-popups/top-navigation/forms/modals/downloads) no funcionan enlaces externos, ventanas ni formularios.
Abierto directamente como pestaña superior, un documento puede navegar a sí mismo (`location=`), cosa que el escáner rechaza.

## ZIP
≤ 5 MB comprimido, ≤ 8 MB descomprimido, ≤ 60 entradas; texto ≤ 512 KB, imágenes/fuentes ≤ 3 MB; ratio por entrada ≤ 200 si > 1 MB.
Rutas con el mismo rigor que `PhpTemplate::safeEntryPath`; sin enlaces simbólicos, sin ocultos (se ignora `__MACOSX/` y `.DS_Store`), nombres finales en minúsculas
`^[a-z0-9][a-z0-9._-]{0,60}$`, doble extensión peligrosa rechazada (`x.php.png`). Se lee entrada a entrada con tope real. `index.html` en la raíz o dentro de
una única carpeta. Extensiones: html (solo `index.html`), css, js, png, jpg, jpeg, webp, gif, svg (escáner SVG), woff, woff2 (magic), txt. Todo js/css/svg se escanea.
TODO(ImageStore): las imágenes solo se validan (finfo + getimagesize + 25 MP). `ImageStore` reencoda a WebP con nombres nuevos, lo que rompería las rutas
relativas del HTML; integrarlo exigiría reescribir referencias. Reencodar quitaría EXIF y polyglots (hoy mitigado por nosniff + CSP sandbox en uploads).

## Cupos
Mes calendario (hora de El Salvador), leídos de `membership_tiers.html_uploads_per_month` y `Access::FREE_MONTHLY_HTML_UPLOADS`: Gratis 1 · Romántico 3 · Pareja 6 · Eterno 12.
Se registra con `Access::tryRecordHtmlUpload` dentro de la transacción de `Sites::create` (fila de usuario `FOR UPDATE`). Un escaneo rechazado no consume cupo
ni deja archivos. La página también consume el cupo de páginas y la vigencia del plan.

## Moderar
`admin/pages.php`: insignia «HTML propio» y enlace «Ver documento aislado» (`/c/{slug}/f`; el envoltorio es `/c/{slug}`). Eliminar la página (o suspender la cuenta)
borra el HTML y los recursos (`Sites::purgeFiles`). El libro `html_uploads` (sha256, bytes, fecha) se conserva con la cuenta.
Nota: la plantilla oculta `html-propio` está `is_active=1`; `create.php`, `preview.php` y `download.php` no la filtran por `kind` (no son de este módulo).
