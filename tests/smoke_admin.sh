#!/usr/bin/env bash
# Prueba de humo del panel de administración contra una instancia RECIÉN creada
# (sin usuarios: el primero que se registre queda como administrador).
#
#   python3 tests/mock_wompi.py 9090 &      # simulador de Wompi SV (el checkout llama a su API)
#   docker compose down -v && docker compose up -d --build && tests/smoke_admin.sh http://localhost:8080 http://127.0.0.1:9090
#
# Nota: nunca se usa `curl | grep -q`. Con `pipefail`, grep cierra la tubería al primer
# acierto y curl muere con código 23, dando falsos negativos. Siempre a variable primero.
set -euo pipefail
B=${1:?base url}; MOCK=${2:?url del simulador de Wompi (tests/mock_wompi.py)}
A=$(mktemp); U=$(mktemp); TPL=$(mktemp --suffix=.html); trap 'rm -f "$A" "$U" "$TPL"' EXIT
fail(){ echo "FALLO: $*" >&2; exit 1; }
get(){ curl -fsS -b "$1" -c "$1" "$B/$2"; }
tok(){ get "$1" "$2" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4; }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }
has(){ case "$1" in *"$2"*) return 0;; *) return 1;; esac; }

[ "$(curl -s $B/healthz.php)" = ok ] || fail healthz

# --- 1. Primer registro => administrador; segundo => usuario normal ----------
T=$(tok "$A" register.php); ADMIN="admin$RANDOM@example.com"
[ "$(code -b "$A" -c "$A" --data-urlencode "_csrf=$T" --data-urlencode "email=$ADMIN" -d 'password=12345678' $B/register.php)" = 303 ] || fail "alta admin"
T=$(tok "$U" register.php); USER="user$RANDOM@example.com"
[ "$(code -b "$U" -c "$U" --data-urlencode "_csrf=$T" --data-urlencode "email=$USER" -d 'password=12345678' $B/register.php)" = 303 ] || fail "alta usuario"

# --- 2. Control de acceso (RBAC) --------------------------------------------
[ "$(code $B/admin/index.php)" = 303 ]             || fail "anónimo debe ir al login"
[ "$(code -b "$U" $B/admin/index.php)" = 403 ]     || fail "usuario sin is_admin debe recibir 403"
[ "$(code -b "$U" $B/admin/templates.php)" = 403 ] || fail "403 en plantillas"
[ "$(code -b "$U" $B/admin/promos.php)" = 403 ]    || fail "403 en promociones"
[ "$(code -b "$U" "$B/admin/template_preview.php?id=1")" = 403 ] || fail "403 en previsualización"
[ "$(code -b "$A" $B/admin/index.php)" = 200 ]     || fail "el primer usuario registrado no quedó como admin: este script necesita una instancia SIN usuarios (docker compose down -v && docker compose up -d)"
has "$(get "$A" admin/index.php)" 'Usuarios registrados' || fail "dashboard sin estadísticas"

# --- 3. CSRF obligatorio en POST de administración --------------------------
[ "$(code -b "$A" -d 'action=settings&ads_enabled=1' $B/admin/promos.php)" = 403 ] || fail "POST sin CSRF debe dar 403"
[ "$(code -b "$A" -d '_csrf=falso&action=settings' $B/admin/promos.php)" = 403 ]   || fail "CSRF inválido debe dar 403"

# --- 4. Subida de plantilla maliciosa: rechazada ----------------------------
T=$(tok "$A" admin/templates.php)
printf '<!doctype html><html><body><?php system($_GET["c"]); ?><img src=x onerror=alert(1)><script>alert(1)</script></body></html>' > "$TPL"
R=$(curl -fsS -b "$A" -c "$A" -F "_csrf=$T" -F 'action=create' -F 'name=Maligna' -F 'slug=maligna' -F 'price_usd=0' -F "html=@$TPL;type=text/html" $B/admin/templates.php)
has "$R" 'código ejecutable' || fail "no se rechazó el PHP embebido"
has "$R" 'onclick'           || fail "no se rechazaron los manejadores en línea"
has "$R" 'sin nonce'         || fail "no se rechazó el script sin nonce"
! has "$(get "$A" admin/templates.php)" 'maligna' || fail "la plantilla maligna no debe guardarse"

# --- 5. Subida de plantilla válida ------------------------------------------
T=$(tok "$A" admin/templates.php)
cat > "$TPL" <<'HTML'
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>{{your_name}}</title>
<script nonce="{{{nonce}}}" src="https://cdn.tailwindcss.com"></script></head>
<body><h1>{{your_name}} &amp; {{partner_name}}</h1><p id="d">{{days_together}}</p><p>{{message}}</p>{{{ad_slot}}}</body></html>
HTML
[ "$(code -b "$A" -c "$A" -F "_csrf=$T" -F 'action=create' -F 'name=Prueba Humo' -F 'slug=prueba-humo' \
    -F 'description=Plantilla de prueba' -F 'price_usd=2.50' -F 'is_premium=1' -F "html=@$TPL;type=text/html" $B/admin/templates.php)" = 303 ] \
    || fail "la plantilla válida debería aceptarse"
LIST=$(get "$A" admin/templates.php)
has "$LIST" 'prueba-humo' || fail "la plantilla no aparece en el listado"
ID=$(echo "$LIST" | tr '\n' ' ' | grep -oP 'prueba-humo.*?template_edit\.php\?id=\K[0-9]+' | head -1)
[ -n "$ID" ] || fail "id de plantilla no encontrado"
has "$(curl -fsS -b "$A" "$B/admin/template_preview.php?id=$ID")" 'Ana' || fail "la previsualización no renderiza"

# --- 6. Cambio de estado y borrado protegido --------------------------------
T=$(tok "$A" admin/templates.php)
curl -s -o /dev/null -b "$A" -c "$A" -d "_csrf=$T" -d 'action=toggle_premium' -d "id=$ID" $B/admin/templates.php
has "$(get "$A" admin/templates.php)" 'ahora es gratuita' || fail "no se pudo pasar de premium a gratuita"

T=$(tok "$U" create.php)     # el usuario normal crea una página con esa plantilla
curl -s -o /dev/null -b "$U" -c "$U" --data-urlencode "_csrf=$T" \
  -d "template_id=$ID&your_name=Ana&partner_name=Luis&start_date=2024-02-14&message=Hola" $B/create.php
T=$(tok "$A" admin/templates.php)
curl -s -o /dev/null -b "$A" -c "$A" -d "_csrf=$T" -d 'action=delete' -d "id=$ID" -d 'delete_file=1' $B/admin/templates.php
LIST=$(get "$A" admin/templates.php)
has "$LIST" 'No se puede eliminar' || fail "debería negarse a borrar una plantilla en uso"
has "$LIST" 'prueba-humo'          || fail "la plantilla en uso desapareció"

# --- 7. Anuncios y aviso global ---------------------------------------------
SLUG=$(get "$U" dashboard.php | grep -o '/c/[a-z0-9]\{8\}' | head -1 | cut -d/ -f3)
[ -n "$SLUG" ] || fail "slug de la página no encontrado"
has "$(curl -fsS $B/c/$SLUG)" 'id="ad-slot"' || fail "por defecto debe haber anuncios"

T=$(tok "$A" admin/promos.php)
R=$(curl -fsS -b "$A" -c "$A" -d "_csrf=$T" -d 'action=settings' \
    --data-urlencode 'ads_html=<script src="https://ads.example/a.js"></script>' $B/admin/promos.php)
has "$R" 'no permitida' || fail "el banner con <script> debe rechazarse"

T=$(tok "$A" admin/promos.php)   # sin ads_enabled => se apagan los anuncios
curl -s -o /dev/null -b "$A" -c "$A" -d "_csrf=$T" -d 'action=settings' -d 'announcement_enabled=1' \
  --data-urlencode 'announcement_text=Rebaja de San Valentín' $B/admin/promos.php
! has "$(curl -fsS $B/c/$SLUG)" 'id="ad-slot"'          || fail "los anuncios debían quedar desactivados"
has "$(get "$U" dashboard.php)" 'Rebaja de San Valent'  || fail "no se muestra el aviso global"

# --- 8. Código de promoción aplicado en el checkout -------------------------
T=$(tok "$A" admin/promos.php)
curl -s -o /dev/null -b "$A" -c "$A" -d "_csrf=$T" -d 'action=promo_create' -d 'code=HUMO50' -d 'discount_percent=50' -d 'max_uses=0' $B/admin/promos.php
has "$(get "$A" admin/promos.php)" 'HUMO50' || fail "el código no se creó"

# Monto (USD) que el servidor envió a Wompi en el último checkout, leído del simulador.
amount(){ curl -s -o /dev/null -b "$U" -c "$U" "$@" $B/checkout_wompi.php; curl -fsS "$MOCK/_last/monto"; }
T=$(tok "$U" dashboard.php); FULL=$(amount -d "_csrf=$T")
T=$(tok "$U" dashboard.php); DISC=$(amount -d "_csrf=$T" -d 'promo=humo50')
# 50 % de 4.99 = 2.495 -> 2.50 (redondeo a centavo): se compara con tolerancia de 1 centavo.
awk -v f="$FULL" -v d="$DISC" 'BEGIN{ exit !(d >= f/2 - 0.011 && d <= f/2 + 0.011 && d < f) }' \
  || fail "descuento no aplicado (completo=$FULL, con código=$DISC)"

T=$(tok "$U" dashboard.php)
curl -s -o /dev/null -b "$U" -c "$U" -d "_csrf=$T" -d 'promo=NOEXISTE' $B/checkout_wompi.php
has "$(get "$U" dashboard.php)" 'no es válido' || fail "un código inexistente debe rechazarse"

# --- 9. Precio Premium fijado desde el panel --------------------------------
T=$(tok "$A" admin/promos.php)
curl -s -o /dev/null -b "$A" -c "$A" -d "_csrf=$T" -d 'action=settings' -d 'premium_price_usd=7.50' $B/admin/promos.php
T=$(tok "$U" dashboard.php); NEW=$(amount -d "_csrf=$T")
[ "$NEW" = 7.5 ] || fail "el precio del panel no se aplicó (esperado 7.5, obtenido $NEW)"

# Un precio inválido se rechaza y no pisa el vigente.
T=$(tok "$A" admin/promos.php)
R=$(curl -fsS -b "$A" -c "$A" -d "_csrf=$T" -d 'action=settings' -d 'ads_enabled=1' -d 'premium_price_usd=4.999' $B/admin/promos.php)
has "$R" 'entre 0.01 y 99999.99' || fail "un precio con 3 decimales debe rechazarse"
T=$(tok "$U" dashboard.php); [ "$(amount -d "_csrf=$T")" = 7.5 ] || fail "el precio inválido pisó al vigente"

echo "SMOKE ADMIN OK"
