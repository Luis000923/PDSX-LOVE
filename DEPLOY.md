# CI/CD de LovePages

```
PR / push ─► CI (ci.yml) ──verde en main──► CD (cd.yml)
             ├ php: lint + PHPStan + PHPUnit (8.2, 8.3)     ├ publish: build → GHCR (sha + latest, SBOM, atestación)
             ├ security: gitleaks + composer audit + Trivy   └ deploy: SSH → docker compose pull/up --wait → rollback si falla
             └ docker: hadolint + build + humo E2E + Trivy
```

## Local
```bash
docker compose up --build        # http://localhost:8080
composer install && composer test && composer stan

# Las pruebas de humo usan un simulador de Wompi SV (el checkout llama a su API). Arráncalo y
# apunta el compose a él (WOMPI_TOKEN_URL/WOMPI_API_URL solo se respetan con WOMPI_ENV=sandbox):
python3 tests/mock_wompi.py 9090 &
export WOMPI_CLIENT_ID=client_dev WOMPI_API_SECRET=secret_dev \
       WOMPI_TOKEN_URL=http://host.docker.internal:9090/connect/token WOMPI_API_URL=http://host.docker.internal:9090
docker compose up -d --build
tests/smoke.sh http://localhost:8080 secret_dev http://127.0.0.1:9090   # registro → pago → webhook

# El panel exige una instancia SIN usuarios: el primero que se registra queda como admin.
docker compose down -v && docker compose up -d --build
tests/smoke_admin.sh http://localhost:8080 http://127.0.0.1:9090        # RBAC, CSRF, plantillas, promos, precio
```

## Una sola vez en GitHub
1. Repo → Settings → Environments → **production** → añade *Required reviewers* (aprobación manual antes de desplegar).
2. Settings → Actions → General → *Workflow permissions*: Read repository contents (los workflows piden lo demás).
3. Secrets del repo (o del environment `production`):

| Secret | Valor |
|---|---|
| `DEPLOY_HOST` / `DEPLOY_USER` | servidor y usuario SSH (usuario con acceso a `docker`) |
| `DEPLOY_PORT` | opcional, por defecto 22 |
| `DEPLOY_PATH` | carpeta en el servidor, p.ej. `/opt/lovepages` |
| `DEPLOY_SSH_KEY` | clave privada **dedicada** al deploy (`ssh-keygen -t ed25519`) |
| `DEPLOY_KNOWN_HOSTS` | salida de `ssh-keyscan -p PORT HOST` (evita MITM) |

4. Si el paquete GHCR es privado, el servidor se autentica con el `GITHUB_TOKEN` del job; no hace falta PAT.

## Una sola vez en el servidor
```bash
mkdir -p /opt/lovepages && cd /opt/lovepages
cp .env.example .env && chmod 600 .env && nano .env
# APP_URL=https://pdsx.org/love · APP_DEBUG=0 · WOMPI_ENV=production · WOMPI_CLIENT_ID · WOMPI_API_SECRET
# DB_HOST=db · DB_PORT=3306 · DB_NAME=lovepages · DB_USER=lovepages · DB_PASSWORD=<larga y aleatoria> · DB_ROOT_PASSWORD=<otra distinta>
```
Con `docker-compose.prod.yml` la base es el servicio `db` (MySQL 8.0, InnoDB, utf8mb4) del propio compose: `DB_HOST=db`.
`DB_ROOT_PASSWORD` solo la usa el contenedor MySQL para inicializarse (la app nunca). Los valores del `.env` no admiten
` #` dentro (se lee como comentario): evítalo en contraseñas. Para un MySQL gestionado/externo, apunta `DB_HOST` a él y
quita el servicio `db` del compose; el usuario necesita `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES`
sobre `DB_NAME` (los DDL los aplica la propia app al arrancar). MySQL **8.0.16+** obligatorio (aplica los `CHECK`).
Instala Docker + Compose v2 y publica el puerto `127.0.0.1:8080` con un proxy inverso con TLS. Caddy:
```
pdsx.org {
    handle_path /love/* { reverse_proxy 127.0.0.1:8080 }
}
```
`handle_path` quita el prefijo `/love`; deja `APP_URL=https://pdsx.org/love` para que los enlaces y la cookie de sesión usen esa ruta.

## Wompi El Salvador (pagos)

Credenciales: [panel.wompi.sv](https://panel.wompi.sv) → tu aplicación → **Client ID** y **API Secret** → `.env`
(`WOMPI_CLIENT_ID`, `WOMPI_API_SECRET`). El API Secret hace dos cosas: pide el token OAuth y firma los
webhooks, así que **solo vive en el `.env` del servidor** (no en git, no en GitHub Secrets del CD).

Flujo: `checkout_wompi.php` → token OAuth (`id.wompi.sv`, se cachea ~1 h en `/tmp`) → `POST api.wompi.sv/EnlacePago`
→ redirección a la `urlEnlace` (solo se aceptan URLs `https://*.wompi.sv`). No hay que registrar la URL del webhook
en el panel: cada enlace lleva la suya (`https://pdsx.org/love/webhook_wompi.php`).

- **Moneda y precio:** siempre USD, en **centavos enteros** en la BD (`payments.amount_in_cents`). Precio: panel admin
  (`settings.premium_price_usd`) > `PREMIUM_PRICE_USD` (.env) > $4.99. Mínimo $0.01.
- **Webhook:** cabecera `wompi_hash` = HMAC-SHA256 hex del cuerpo **crudo** con el API Secret, validada con `hash_equals()`
  antes de leer el JSON (401 si falla). Solo `ResultadoTransaccion = "ExitosaAprobada"` (comparación exacta) activa Premium.
  El monto recibido debe igualar al registrado; es idempotente (un reintento no repite efectos).
- **`WOMPI_ENV=production`** (o cualquier valor distinto de `sandbox`, o vacío): una transacción con `EsProductiva=false`
  se ignora y las variables `WOMPI_TOKEN_URL/WOMPI_API_URL` no tienen efecto. Ponlo siempre en producción.
- **Cabeceras con guion bajo:** `wompi_hash` lleva `_`. Apache la lee bien (el código usa `apache_request_headers()`),
  pero **nginx la descarta** por defecto: si pones nginx delante, añade `underscores_in_headers on;`. Caddy la reenvía sin cambios.
- Los pagos rechazados no generan webhook (Wompi solo notifica los exitosos): quedan `PENDING` y el panel no los cuenta como ingreso.
- Antes de vender: haz una compra de prueba en sandbox y una real de $0.01–$1 en producción para validar de extremo a extremo.

## Volúmenes (datos que sobreviven a cada despliegue)
| Volumen | Ruta en el contenedor | Contenido |
|---|---|---|
| `lovedb` | `/var/lib/mysql` (contenedor `db`) | datos de MySQL |
| `lovetemplates` | `/var/www/app/templates` | plantillas (el panel admin las edita) |
| `lovethumbs` | `/var/www/app/public/assets/thumbs` | miniaturas subidas |

`templates` se siembra con las plantillas de la imagen **solo la primera vez**. Si cambias una plantilla base en git, no llegará a producción sola: edítala desde el panel admin, o borra el volumen `lovetemplates` (perderías las ediciones del panel).

## Panel de administración (`/love/admin/`)

**Quién entra.** El control está en `Admin::guard()`, que corre en la primera línea de cada
página del panel: sin sesión redirige al login; con sesión pero sin `users.is_admin = 1`
devuelve **403** y lo anota en `admin_audit`. No hay ninguna otra vía de acceso.

**Quién es administrador.** El **primer usuario registrado** de la instalación
(`public/register.php` lo marca dentro de la misma transacción del alta). En bases que
ya existían antes del panel, la migración promueve al usuario más antiguo. Después, los
cambios se hacen solo por CLI:

```bash
docker compose -f docker-compose.prod.yml exec -T web php bin/make_admin.php                    # listar
docker compose -f docker-compose.prod.yml exec -T web php bin/make_admin.php tu@correo.com      # conceder
docker compose -f docker-compose.prod.yml exec -T web php bin/make_admin.php otro@correo.com --revoke
```
Revocar al último administrador está prohibido, para no dejar la instalación sin acceso.

**Qué hay dentro.**

| Sección | Qué hace |
|---|---|
| `admin/index.php` | Usuarios, premium y conversión, páginas creadas, pagos por estado, ingresos Wompi, últimos movimientos y auditoría |
| `admin/templates.php` | Listado, subida de `.html`, cambio gratis↔premium, activar/desactivar y borrado |
| `admin/template_edit.php` | Nombre, descripción, precio, miniatura y reemplazo del `.html` |
| `admin/template_preview.php` | Render con datos de ejemplo (nunca con datos reales de usuarios) |
| `admin/promos.php` | Banner de anuncios, aviso global, precio Premium y códigos de descuento |

**Cómo se filtra lo que se sube.** `Admin::validateTemplateHtml()` **rechaza** (no "limpia")
todo `.html` que contenga código de servidor (`<?php`, `<%`), manejadores en línea
(`onclick=`), URIs `javascript:`/`data:text/html`, `<script>` sin `nonce="{{{nonce}}}"`,
recursos externos fuera de la CSP, marcadores desconocidos o cualquier `{{{crudo}}}` que no
sea `nonce` o `ad_slot`. Los datos del usuario nunca se interpretan como plantilla
(`Template::render` hace una sola pasada y escapa con `htmlspecialchars`). El banner de
anuncios pasa por un filtro aún más estricto (`validateAdHtml`): ni scripts, ni iframes, ni
imágenes de terceros.

**Precios y promociones.** El precio del panel (`settings.premium_price_usd`) manda sobre
`PREMIUM_PRICE_USD` del `.env`. El monto lo calcula siempre el servidor: del formulario solo
llega el *código*, nunca el descuento, y nunca baja de $0.01 (mínimo de Wompi). El
contador de usos de un código solo avanza cuando el webhook firmado confirma `APPROVED`.

**Borrado de plantillas.** Nunca se borra una plantilla con páginas asociadas (habría que
desactivarla). El `.html` del disco solo se elimina si ninguna otra fila lo referencia.

**Migración de esquema.** `db_migrate()` (en `config/database.php`) se dispara sola cuando la versión anotada en la tabla
`schema_version` va por detrás de `DB_SCHEMA_VERSION`. Aplica `database/schema.sql` (todo `CREATE ... IF NOT EXISTS`
/ `INSERT IGNORE`, idempotente). **MySQL no admite DDL transaccional** (cada `CREATE/ALTER` confirma solo), así que
la garantía es otra: un bloqueo `GET_LOCK` impide que dos contenedores migren a la vez, cada paso se puede repetir
sin daño y la versión se anota **al final**; si algo falla queda "pendiente" y se reintenta en el siguiente arranque.
Haz copia de la BD antes de desplegar una versión que suba `DB_SCHEMA_VERSION`.

**Venir de SQLite (una sola vez).** Con la app ya apuntando a un MySQL **vacío**, copia el `store.sqlite` antiguo
junto al compose y ejecuta (conserva ids y relaciones, en una sola transacción; se niega si MySQL ya tiene datos):
```bash
docker compose -f docker-compose.prod.yml run --rm -v "$PWD/store.sqlite:/import/store.sqlite:ro" web php bin/import_sqlite.php /import/store.sqlite
```
Si el origen no tenía administradores, promueve al usuario más antiguo. Después borra el `.sqlite` del servidor (contiene hashes de contraseñas).

## Operación
- **Rollback manual:** `TAG=<sha anterior> IMAGE=ghcr.io/<owner>/<repo> docker compose -f docker-compose.prod.yml up -d --wait`. El deploy guarda el último tag bueno en `.current_tag`.
- **Backup de la BD** (volumen `lovedb`), ejemplo diario por cron (volcado consistente sin bloquear escrituras):
  `docker compose -f docker-compose.prod.yml exec -T db sh -c 'mysqldump --single-transaction --routines -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip > lovepages-$(date +%F).sql.gz`
  y copia el `.sql.gz` fuera del servidor. Restaurar: `gunzip -c lovepages-AAAA-MM-DD.sql.gz | docker compose -f docker-compose.prod.yml exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'`.
- **Tests locales:** `docker run -d --name lp-mysql -p 127.0.0.1:3306:3306 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lovepages_test -e MYSQL_USER=lovepages -e MYSQL_PASSWORD=lovepages mysql:8.0`
  y luego `composer test` / `composer stan`. `tests/bootstrap.php` exige que `DB_NAME` termine en `_test` (el test de BD la vacía).
- **Pruebas locales del checkout sin cuenta Wompi:** `python3 tests/mock_wompi.py 9090` y, en `.env`, `WOMPI_ENV=sandbox`,
  `WOMPI_TOKEN_URL=http://host.docker.internal:9090/connect/token`, `WOMPI_API_URL=http://host.docker.internal:9090`.
- **Rotar secretos de Wompi:** edita `.env` en el servidor y `docker compose -f docker-compose.prod.yml up -d`.
- Despliegue manual: Actions → CD → *Run workflow*.

## Plantillas PHP y membresías (v5)

- La imagen ahora incluye la extensión `zip` (subida de plantillas PHP) y un volumen nuevo `lovetpl` en `public/assets/tpl` (assets estáticos de esas plantillas). Reconstruye la imagen: `docker compose build`.
- La migración v5 corre sola en el primer arranque: crea `membership_tiers` (Romántico/Pareja/Eterno), añade columnas y pasa a los Premium previos a **Eterno**.
- Precios y beneficios de los niveles: tabla `membership_tiers` (ver `docs/MEMBRESIAS.md`).

## Membresías con vencimiento (v7)

- La migración v7 corre sola en el primer arranque: añade `users.membership_expires_at` (NULL = sin vencimiento; las cuentas actuales no cambian) y `membership_tiers.duration_months` (Romántico y Pareja 1 mes, Eterno 2).
- Despliega `database/schema.sql` y `config/database.php` **juntos** (la semilla de los planes y la migración van en pares); reconstruye la imagen: `docker compose build`.
- Nuevas variables opcionales en `.env` para las páginas legales: `LEGAL_ENTITY` y `LEGAL_EMAIL` (correo para solicitudes de privacidad y reembolsos por error de cobro).

## Panel de administración v2 (migración v9)

- La migración v9 corre sola en el primer arranque: añade `payments.method/approved_by/admin_note/fulfilled_at`, `promos.scope/tier_id/note/created_by` (el descuento admite 1–100 %), la tabla `promo_redemptions` (un canje por usuario y código) y `users.is_suspended/suspended_reason/suspended_at`.
- Despliega `database/schema.sql` y `config/database.php` **juntos** y reconstruye la imagen: `docker compose build`.
- Cupones al 100 %: el pago se registra como `PROMO` con monto 0 y no pasa por Wompi. La aprobación manual de pagos y los ajustes de cuenta quedan en `admin_audit` (Panel → Actividad).
- Ver `docs/ADMIN.md` para el resumen de módulos.

