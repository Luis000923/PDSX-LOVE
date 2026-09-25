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
tests/smoke.sh http://localhost:8080 evt_x            # flujo de usuario (registro → pago → webhook)

# El panel exige una instancia SIN usuarios: el primero que se registra queda como admin.
docker compose down -v && docker compose up -d --build
tests/smoke_admin.sh http://localhost:8080            # RBAC, CSRF, subida de plantillas, promos
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
cp .env.example .env && nano .env      # APP_URL, llaves de Wompi, DB_PATH=/var/www/data/store.sqlite
```
Instala Docker + Compose v2 y publica el puerto `127.0.0.1:8080` con un proxy inverso con TLS. Caddy:
```
pdsx.org {
    handle_path /love/* { reverse_proxy 127.0.0.1:8080 }
}
```
`handle_path` quita el prefijo `/love`; deja `APP_URL=https://pdsx.org/love` para que los enlaces y la cookie de sesión usen esa ruta.
Configura en Wompi la URL de eventos: `https://pdsx.org/love/webhook_wompi.php`.

## Volúmenes (datos que sobreviven a cada despliegue)
| Volumen | Ruta en el contenedor | Contenido |
|---|---|---|
| `lovedata` | `/var/www/data` | base SQLite |
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

**Precios y promociones.** El precio del panel (`settings.premium_price_cop`) manda sobre
`PREMIUM_PRICE_COP` del `.env`. El monto lo calcula siempre el servidor: del formulario solo
llega el *código*, nunca el descuento, y nunca baja de los 1.500 COP mínimos de Wompi. El
contador de usos de un código solo avanza cuando el webhook firmado confirma `APPROVED`.

**Borrado de plantillas.** Nunca se borra una plantilla con páginas asociadas (habría que
desactivarla). El `.html` del disco solo se elimina si ninguna otra fila lo referencia.

**Migración de esquema.** `db_migrate()` (en `config/database.php`) se dispara sola cuando
`PRAGMA user_version` va por detrás de `DB_SCHEMA_VERSION`. Añade `users.is_admin`,
`templates.description/price_cop/thumbnail`, `payments.promo_code` y las tablas `settings`,
`promos` y `admin_audit`, sin tocar los datos existentes. Es idempotente. Haz copia de la
BD antes del primer despliegue con el panel.

## Operación
- **Rollback manual:** `TAG=<sha anterior> IMAGE=ghcr.io/<owner>/<repo> docker compose -f docker-compose.prod.yml up -d --wait`. El deploy guarda el último tag bueno en `.current_tag`.
- **Backup de la BD** (volumen `lovedata`), ejemplo diario por cron:
  `docker compose -f docker-compose.prod.yml exec -T web php -r '(new PDO("sqlite:/var/www/data/store.sqlite"))->exec("VACUUM INTO \"/var/www/data/backup.sqlite\"");'`
  y copia `backup.sqlite` fuera del servidor.
- **Rotar secretos de Wompi:** edita `.env` en el servidor y `docker compose -f docker-compose.prod.yml up -d`.
- Despliegue manual: Actions → CD → *Run workflow*.
