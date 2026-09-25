# Economía de creadores

Plantillas **públicas** subidas por usuarios (`templates.kind = 'utpl'`), revisión de un admin, reparto de ingresos en monedas,
colaboradores destacados y premios automáticos. Código: `src/Creators.php` (config, envío, revisión, consultas),
`src/CreatorEarnings.php` (ganancias), `src/CreatorAwards.php` (premios). Migración `db_migrate_v13` + `database/schema.sql`.

## Modelo

- HTML reutilizable con los marcadores del motor `Template` (`{{your_name}}`, `{{partner_name}}`, `{{start_date}}`,
  `{{days_together}}`, `{{message}}`, `{{img_*}}`, `{{#if img_x}}`). Un solo `.html` (los `.zip` no se admiten en plantillas públicas todavía).
- Se guarda **fuera del webroot**: `storage/user_templates/{slug}.html` (slug `u-` + 10 hex). Un reenvío se guarda como `{slug}.new.html` y
  solo reemplaza al vigente al aprobarse (el contenido sin revisar nunca llega a páginas ya publicadas).
- Columnas nuevas en `templates`: `owner_user_id`, `review_status` (`pending|approved|rejected|withdrawn`, por defecto `approved` para las oficiales),
  `review_note`, `credit_alias` (foto instantánea del alias al aprobar), `submitted_at`, `reviewed_at`, `reviewed_by`.
- `template_earnings` (ledger de ganancias) y `users.bonus_tier_id/bonus_tier_expires_at` (mejora temporal de plan).
- Índice único `users.display_name` (alias único; se omite con aviso en el log si la base tiene duplicados).

## Flujo

1. `public/upload_html.php` («Subir mi plantilla», flujo único; `creator_upload.php` redirige a `?modo=publica`). En «Mis páginas», el botón «Publicar en la Galería» de una página de HTML propio abre `?modo=publica&desde={id}` con su HTML precargado (`Creators::htmlFromSite`: se relee de `storage/user_html`, se vuelve a escanear como plantilla; la página privada no cambia y no se toca `html_uploads`). Modo público: nombre ≤60, descripción ≤200, categoría, nº de fotos 0–12
   (`image_spec {"repeat":{"prefix":"foto","label":"Foto {n}","min":0,"max":N}}`), miniatura obligatoria (`ImageStore::thumbnail`: WebP 800×480, recorte
   centrado, `public/assets/thumbs/`), precio en monedas (config), casilla «Incluir en el cupo mensual de membresías» (`is_premium=1`+`membership_unlocks=1`;
   sin marcar: solo monedas) y casilla de conformidad. Alias: `users.display_name`; si falta se pide y se guarda **sin** activar `show_in_rankings`.
2. Escaneo: `UserHtml::inspect` (tamaño ≤512 KB, MIME, UTF-8, `HtmlScanner`) + `HtmlScanner::scanTemplate`: marcadores solo en texto/atributos inocuos
   (nunca en `<script>`, `<style>`, `on*`, `style` ni URLs salvo `{{img_*}}` en `src/href/poster`), sin `{{{crudo}}}`, sin marcadores desconocidos y con al menos un
   marcador de datos (si no, debe ser HTML propio privado).
3. `Creators::submit`: transacción con la fila del usuario bloqueada; límites `max_pending` y `max_templates`; inserta `pending`, `is_active=0` y escribe archivos.
   Un envío rechazado o fallido no deja filas ni archivos. **No toca `html_uploads`**: el cupo de HTML propio y el de plantillas públicas son independientes.
4. `public/admin/creators.php`: pestañas Pendientes/Aprobadas/Rechazadas/Retiradas, vista previa aislada (`admin/creator_preview.php`, CSP sandbox),
   aprobar (ajusta precio, categoría y cupo), rechazar (nota obligatoria) o retirar; formulario de configuración. Todo con CSRF y `Admin::log`.
5. `public/creator.php` («Mis plantillas públicas»): estado, nota, usos por otros, ganancias pendientes/pagadas, retirar, reenviar con archivo nuevo.
6. Galería y `create.php`: solo `Creators::PUBLIC_WHERE` (activa **y** aprobada). `Sites::create` revalida el estado dentro de la transacción (un id forzado se rechaza).
   Las tarjetas muestran «Por {alias}».

Las páginas creadas con una plantilla **retirada** siguen vivas y renovables (sin ganancias); con una **rechazada** no se sirven.

## Aislamiento

Como el HTML lo escribe un tercero, **siempre** se sirve como el HTML propio: `/c/{slug}` (envoltorio de confianza) + `/c/{slug}/f` (`frame.php`) con
`Creators::render` (mismo motor, todos los valores escapados, un solo pase de regex) y `UserHtml::sendSandboxHeaders()` (CSP `sandbox allow-scripts`, sin
`allow-same-origin`). Las vistas previas (`preview.php?t=…`, admin) también van en un iframe sandbox (`?frame=1` sin sesión); nunca en origen propio.

## Reparto de ingresos

Configuración (`settings.creators_config`, JSON validado por `Creators::validateConfig`, defecto `Creators::DEFAULTS`, editable en admin):

| Clave | Defecto | Rango |
|---|---|---|
| `share_pct` | 30 | 0–90 |
| `share_on_quota_unlock` | true | bool |
| `min_price` / `max_price` | 5 / 200 | 1–10000 |
| `max_pending` / `max_templates` | 3 / 30 | 1–50 / 1–500 |
| `success_uses` | 5 | 1–100000 |
| `milestones` | 1→20 (Creador), 3→60, 5→120 (Creador Estrella), 10→300 | ≤6, crecientes |
| `month_min_templates` / `month_min_uses` / `month_coins` | 3 / 20 / 80 | |
| `top_tier_slug` / `top_tier_days` | pareja / 7 | 0 días = desactivado |

- Cuando **otra** persona crea o renueva una página con una plantilla aprobada, dentro de la **misma transacción** del comprador (tras cobrar/cubrir) se hace
  `INSERT IGNORE` en `template_earnings` (`UNIQUE(template_id, ref)`, `ref = create:{siteId}` o `renew:{siteId}:{YmdHis}`).
  `share_coins = floor(base × share_pct / 100)`; si es 0 no se registra. Nunca hay comisión por autocompra.
- `kind = coins`: base = monedas realmente gastadas. `kind = quota`: la membresía cubre el uso con un cupo **nuevo** del mes; base = precio nominal (financia la plataforma;
  solo si `share_on_quota_unlock`). Repetir la misma plantilla el mismo mes no consume cupo ni vuelve a pagar. Si el cupo se agota, cobra monedas y paga como `coins`.
- **Sin deadlocks**: la tabla no tiene FK a `creator_id` (una FK tomaría un bloqueo compartido sobre la fila del creador; dos usuarios que se compran mutuamente se
  bloquearían). El pago real ocurre **después del commit** en `CreatorEarnings::settle($creatorId)`: transacción propia, bloquea primero al usuario y luego sus ganancias
  `pending` (`FOR UPDATE`), acredita la suma con `Coins::credit(..., 'creator_share', 'earn:a-bxN')` y marca `paid`. Idempotente y reintentable; se invoca tras cada venta, al abrir
  `creator.php`/`profile.php`/`admin/creators.php` y con `bin/settle_creators.php` (cron: `*/15 * * * * php bin/settle_creators.php`).
- Invariante: el saldo del creador cuadra con `coin_transactions`.

## Colaboradores destacados (`public/colaboradores.php`)

Éxito = plantilla aprobada con ≥ `success_uses` páginas creadas por **otros** usuarios. Ranking: nº de plantillas exitosas, luego usos totales. Solo `credit_alias`,
conteos e insignias; nunca correos. Suspendidos excluidos. Estado vacío amable.

## Premios (`Awards::grant`, idempotente vía `award_grants`/`user_badges`)

- Hitos por plantillas aprobadas (`cms:{n}`), evaluados al aprobar.
- Cierre mensual (`cmm:{ym}`, `cmt:{ym}`): gancho dentro de `Awards::closeMonth` (misma transacción; `closePendingMonths` lo ejecuta perezosamente y `bin/close_awards.php` por cron).
  Solo meses ≥ `settings.creators_launch_month` (no retroactivo). El n.º 1 del mes recibe la mejora temporal.
- Insignias nuevas: `creador`, `creador_estrella`, `colaborador_mes` (SVG en `public/assets/img/awards/`).

## Mejora temporal de plan

`users.bonus_tier_id` + `bonus_tier_expires_at` **no** tocan el plan comprado (`membership_tier_id/expires_at`).

- `Access::userTier()` = el de mayor `sort_order` entre el principal vigente y el bonus vigente (empate: principal). Con el bonus vencido devuelve exactamente el principal.
- `Access::mainTier()` = solo el principal; lo usan `canPurchaseTier`, las pantallas de renovación (`layout.php`) y `AdminUsers::grantPlan`.
  `wasMember`, `membershipExpiresAt` y `membershipDaysLeft` solo miran el principal («Tu plan venció» no se dispara por el bonus).
- `isMember` es verdadero con cualquiera de los dos (los beneficios se aplican); el bonus no es comprable ni renovable.
- Los cupos ya guardados (`site_creations.tier_id`, `template_unlocks`, `html_uploads`) no se recalculan: solo cambia el permiso de los usos nuevos.
- Sitios que leen la fila de usuario: `bootstrap.php` (`load_session_user` incluye las columnas bonus), `view.php` (`ad_free` considera el bonus), `applyTierPurchase` (ignora el bonus y lo deja intacto).
  Las vistas de admin que hacen su propio SELECT muestran el plan principal.

## Despliegue

Copiar juntos `config/database.php` y `database/schema.sql`; el volumen `storage` (ya montado) guarda `storage/user_templates`. Añadir a cron `bin/settle_creators.php`. Sin cambios de URL existentes.

## Flujo unificado de subida

Paso 1 archivo, paso 2 privada/pública (tarjetas-radio; los grupos de campos se alternan con CSS `:has()`, sin JS), paso 3 fotos (nº 0–12). Privada: las fotos reales se suben en la misma petición (`TemplateImages::stage` + `committer` dentro de `Sites::create`). Pública: el nº solo define `image_spec` (`{"repeat":{"prefix":"foto",...}}`, claves `foto_1`…`foto_N`) y los compradores suben las suyas en `create.php`. Marcadores: `{{img_foto_1}}`, `{{img_count}}`, `{{#if img_foto_1}}…{{/if}}`.
