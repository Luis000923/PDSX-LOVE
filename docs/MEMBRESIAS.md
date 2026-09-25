# Membresías de LovePages: investigación y planes

## Investigación (mercado de "love pages")

Todos los competidores revisados cobran **pago único**, no suscripción:

| Servicio | Modelo | Precio |
|---|---|---|
| [YourLovePage](https://www.yourlovepage.com/pricing) | Upgrade Premium único (URL propia, sin marca de agua, fotos ilimitadas) | 9,99 USD |
| [Digital Love Story](https://www.digitallovestory.life/) | Experiencias de pago, la página vive 6 meses | desde 2,99 USD; la más popular 4,99 USD |
| [LovePage.io](https://www.lovepage.io/) | Gratis para crear, upgrade único | n/d |
| [Lovely](https://www.lovelydesign.in/blog/best-romantic-website-builders-2026-comparison) | Marketplace de 37 plantillas por ocasión (propuesta, aniversario, San Valentín…) | n/d |

Conclusiones aplicadas:
1. El punto de entrada del mercado está en 2–3 USD; un primer escalón de **1 USD** reduce la fricción de pago (y en Wompi SV el mínimo del enlace es 0,01 USD).
2. El techo razonable de un pago único ronda los 8–10 USD.
3. Los diferenciadores que se cobran son: sin marca/anuncios, más páginas y acceso a plantillas premium.
4. Los marketplaces se organizan por **ocasión/categoría** y destacan lo popular.

Limitación: la búsqueda no devolvió precios de todos los servicios (marcados n/d); los precios de abajo son una decisión de producto, no un dato de mercado.

## Planes (pago único vía Wompi) y monedas

| | Gratis | Romántico | Pareja | Eterno |
|---|---|---|---|---|
| Precio (USD) | 0 | **1,00** | **3,99** | **7,99** |
| Páginas activas | 3 | 2 | 5 | 12 |
| Vida de cada página | 3 días | 3 días | 7 días | 14 días |
| Bono inicial de monedas | 0 | 10 | 45 | 100 |
| Sin anuncios | no | no | sí | sí |
| Bono en recargas | 0 % | 0 % | +10 % | +20 % |

Monedas (`users.coins`, libro en `coin_transactions`):
- Cada plantilla tiene un costo en monedas (`templates.price_coins`, editable en el admin; 0 = gratis). Se descuenta al crear la página y de nuevo al renovarla; si ya se compró la plantilla suelta en USD, no se cobra.
- Tienda en `/tienda.php` (membresías y monedas). Recargas de $1, $3, $5 y $10 → 10 monedas por dólar + bono del paquete ($1: 0 %, $3: +15 %, $5: +20 %, $10: +25 %) **más** el % del plan (Pareja +10 %, Eterno +20 %), sumados. Así siempre se reciben más monedas por dólar al pagar más (sin plan: 10, 11,3, 12 y 12,5 monedas/$). El total se fija en el servidor al crear el pago (`payments.coins`) y el webhook firmado de Wompi lo abona una sola vez. Los códigos de promoción no aplican a recargas.
- El bono inicial del plan se abona al aprobarse su pago.

Caducidad: `user_sites.expires_at` (UTC) = creación + días del plan. Pasada la fecha, `/c/{slug}` responde 410 y la página no cuenta contra el límite; se renueva desde «Mis páginas» (cuesta las monedas de la plantilla). Las páginas anteriores quedan con `expires_at` NULL (sin caducidad).

Reglas:
- Solo se puede pasar a un plan **superior** o **renovar el mismo** (se paga el precio completo; no hay prorrateo). No se baja a un plan inferior mientras el superior esté vigente.
- Migración v6: los planes se renombran conservando su id (bronce→romántico, plata→pareja, oro→eterno). Los Premium previos pasan a **Eterno**.
- El precio y los beneficios viven en `membership_tiers`; el servidor nunca acepta precios ni monedas del cliente.

Vigencia de las membresías (migración v7):
- Cada plan dura `membership_tiers.duration_months` (Romántico y Pareja: 1 mes; Eterno: 2 meses). Sin renovación automática.
- `users.membership_expires_at` (UTC): NULL = sin vencimiento (cuentas anteriores a v7). Pasada la fecha el usuario vuelve solo al plan gratuito (`Access::userTier()` devuelve null); no hace falta ninguna tarea programada.
- Comprar el mismo plan vigente suma otro período desde su vencimiento; comprar uno superior parte de la fecha de pago. El bono de monedas del plan se abona en cada compra o renovación. Las monedas no vencen.
- Las páginas ya creadas conservan su propia caducidad (`user_sites.expires_at`) aunque el plan venza; solo cambian los límites para crear nuevas.

## Plan gratuito: 3 páginas al mes

- Sin plan efectivo (nunca tuvo o ya venció): `Access::FREE_MONTHLY_PAGES` = 3 páginas **creadas por mes calendario** (más 1 por cada plantilla extra comprada). Cada página vive `Access::FREE_SITE_DAYS` = 3 días.
- El mes se mide en hora de El Salvador (UTC-6, sin horario de verano): del día 1 00:00 local al último día 23:59:59; se reinicia el día 1 a las 00:00 local (06:00 UTC). `created_at` se guarda en UTC.
- Borrar una página NO devuelve cupo. **Renovar** una página (vencida o vigente) consume 1 cupo mensual.
- Los planes de pago no cambian: límite de páginas activas (`max_sites`) y su `site_days`; no usan la cuota mensual.
- Tabla `site_creations` (migración v8): una fila por creación/renovación (`user_id`, `tier_id` NULL = gratuito, `kind` create|renew, `created_at` UTC). La cuota cuenta solo filas con `tier_id IS NULL` del mes; al vencer un plan de pago, sus filas con tier no cuentan. v8 rellena una fila `create` por página existente (tier NULL).
- API: `Access::siteUsage()`, `isFreePlan()`, `atSiteLimit()`, `monthResetLabel()`.
