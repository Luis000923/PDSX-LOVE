#!/usr/bin/env bash
# Prueba de humo E2E por HTTP contra una instancia en ejecución + el simulador de Wompi SV.
#
#   python3 tests/mock_wompi.py 9090 &
#   tests/smoke.sh <base-url> <WOMPI_API_SECRET> <mock-url>
#   tests/smoke.sh http://127.0.0.1:8080 secret_ci http://127.0.0.1:9090
#
# La instancia debe arrancar con WOMPI_ENV=sandbox, WOMPI_CLIENT_ID/WOMPI_API_SECRET y
# WOMPI_TOKEN_URL / WOMPI_API_URL apuntando al simulador (visto desde el contenedor).
#
# Nota: nunca `curl | grep -q` con pipefail (grep cierra la tubería y curl muere con 23).
set -euo pipefail
B=${1:?base url}; SECRET=${2:?WOMPI_API_SECRET}; MOCK=${3:?mock url}
J=$(mktemp); trap 'rm -f "$J"' EXIT
fail(){ echo "FALLO: $*" >&2; exit 1; }
has(){ case "$1" in *"$2"*) return 0;; *) return 1;; esac; }
tok(){ curl -fsS -b "$J" -c "$J" "$B/$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4; }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }
mock(){ curl -fsS "$MOCK/$1"; }

[ "$(curl -s $B/healthz.php)" = ok ] || fail healthz
[ "$(mock '')" = wompi-mock ] || fail "el simulador de Wompi no responde en $MOCK"
[ "$(code -d 'email=a@b.co&password=12345678' $B/register.php)" = 403 ] || fail "registro sin CSRF debe dar 403"

# --- oferta Premium para visitantes: dos caminos ------------------------------
HOME=$(curl -fsS $B/)
has "$HOME" 'Crear cuenta y pagar'          || fail "la portada debe ofrecer 'Crear cuenta y pagar'"
has "$HOME" 'Tengo un código de promoción'  || fail "la portada debe ofrecer 'Tengo un código'"
has "$HOME" 'register.php?next=premium'     || fail "el camino sin código debe ir a registro con next=premium"
has "$HOME" 'register.php?next=code'        || fail "el camino con código debe ir a registro con next=code"
! has "$HOME" 'name="promo"'                || fail "un visitante no debe ver el campo de código suelto"

# --- cuenta (con intención de pago), página y plantillas -----------------------
T=$(tok "register.php?next=premium")
EMAIL="smoke$RANDOM@example.com"
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -c "$J" --data-urlencode "_csrf=$T" --data-urlencode "email=$EMAIL" -d 'password=12345678' "$B/register.php?next=premium")
[[ "$LOC" == *"dashboard.php?offer=1"* ]] || fail "tras registrarse con next=premium debe ir al pago (obtenido: '$LOC')"

# Un next hostil nunca produce una redirección externa (se prueba con otra cuenta, sin sesión).
J2=$(mktemp); T2=$(curl -fsS -c "$J2" "$B/register.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
LOC2=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J2" -c "$J2" --data-urlencode "_csrf=$T2" --data-urlencode "email=evil$RANDOM@example.com" -d 'password=12345678' "$B/register.php?next=https://evil.example")
rm -f "$J2"
[[ "$LOC2" != *evil.example* && "$LOC2" == "$B/"* ]] || fail "next hostil produjo una redirección externa: '$LOC2'"

T=$(tok create.php)
[ "$(code -b "$J" -c "$J" --data-urlencode "_csrf=$T" -d 'template_id=1&your_name=Ana&start_date=2024-02-14' \
  --data-urlencode 'partner_name=<script>alert(1)</script>' --data-urlencode 'message=Hola {{your_name}}' $B/create.php)" = 303 ] || fail crear

[ "$(code $B/download.php?id=1)" = 303 ] || fail "download.php sin sesión debe redirigir"
DASH=$(curl -fsS -b "$J" $B/dashboard.php)
SLUG=$(echo "$DASH" | grep -o '/c/[a-z0-9]\{8\}' | head -1 | cut -d/ -f3)
[ -n "$SLUG" ] || fail "slug no encontrado"
PAGE=$(curl -fsS $B/c/$SLUG)
has "$PAGE" '&lt;script&gt;alert(1)' || fail "XSS no escapado"
has "$PAGE" 'id="ad-slot"' || fail "usuario gratis debe ver anuncios"
has "$DASH" 'USD' || fail "el dashboard debe mostrar el precio en USD"
has "$DASH" 'Pagar $4.99 con tarjeta'      || fail "con sesión debe verse el pago directo (sin código)"
has "$DASH" 'Tengo un código de promoción' || fail "con sesión debe verse la opción de código"
! has "$DASH" '<details class="group rounded-xl border border-rose-200" open' || fail "el desplegable de código debe empezar cerrado"
CODE_PAGE=$(curl -fsS -b "$J" "$B/dashboard.php?offer=code")
has "$CODE_PAGE" 'border-rose-200" open'   || fail "?offer=code debe abrir el desplegable del código"

T=$(tok create.php)
R=$(curl -fsS -b "$J" -c "$J" --data-urlencode "_csrf=$T" -d 'template_id=2&your_name=A&partner_name=B&start_date=2024-02-14&message=x' $B/create.php)
has "$R" 'requiere Premium' || fail "plantilla premium debe bloquearse"

# --- checkout: token OAuth + Enlace de Pago ---------------------------------
[ "$(code -X POST -d '_csrf=x' $B/checkout_wompi.php)" = 403 ] || fail "checkout sin sesión/CSRF debe rechazarse"

T=$(tok dashboard.php)
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -c "$J" --data-urlencode "_csrf=$T" $B/checkout_wompi.php)
[[ "$LOC" == https://lk.wompi.sv/* ]] || fail "debe redirigir a la urlEnlace de Wompi (obtenido: '$LOC')"

REF=$(mock _last/identificador); AMT=$(mock _last/monto)
[[ "$REF" == LP-* ]]   || fail "identificadorEnlaceComercio inesperado: '$REF'"
[ "$AMT" = 4.99 ]      || fail "el monto debe fijarlo el servidor en USD (4.99), llegó '$AMT'"
[ "$(mock _last/urlwebhook)" = "$B/webhook_wompi.php" ] || fail "urlWebhook incorrecta: $(mock _last/urlwebhook)"

# El monto jamás se toma del cliente: campos extra en el POST se ignoran.
T=$(tok dashboard.php)
curl -s -o /dev/null -b "$J" -c "$J" --data-urlencode "_csrf=$T" -d 'amount=0.01&monto=0.01&price=0.01' $B/checkout_wompi.php
[ "$(mock _last/monto)" = 4.99 ] || fail "el servidor aceptó un monto del cliente"

# El token OAuth se cachea: dos checkouts, un solo token pedido al simulador (por instancia).
TOKENS_1=$(mock _stats/tokens)
T=$(tok dashboard.php)
curl -s -o /dev/null -b "$J" -c "$J" --data-urlencode "_csrf=$T" $B/checkout_wompi.php
[ "$(mock _stats/tokens)" = "$TOKENS_1" ] || fail "el token OAuth no se está cacheando"
REF=$(mock _last/identificador)       # el pago que vamos a "cobrar" es el último creado

# --- webhook -----------------------------------------------------------------
sign(){ printf '%s' "$1" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}'; }
body(){  # body <identificador> <monto> <resultado>
  printf '{"IdCuenta":"a1","FechaTransaccion":"2026-09-24T12:00:00","Monto":%s,"ModuloUtilizado":"EnlacePago","IdTransaccion":"tx-%s","ResultadoTransaccion":"%s","EsProductiva":false,"EnlacePago":{"Id":1,"IdentificadorEnlaceComercio":"%s","NombreProducto":"LovePages Premium"}}' "$2" "$RANDOM" "$3" "$1"
}
post(){  # post <cuerpo> <hash>  -> código HTTP
  curl -s -o /dev/null -w '%{http_code}' -H "wompi_hash: $2" --data-binary "$1" $B/webhook_wompi.php
}
premium(){ has "$(curl -fsS -b "$J" $B/dashboard.php)" 'Cuenta Premium'; }

OK=$(body "$REF" 4.99 ExitosaAprobada)
[ "$(code -X GET $B/webhook_wompi.php)" = 405 ]                          || fail "GET al webhook debe dar 405"
[ "$(code --data-binary "$OK" $B/webhook_wompi.php)" = 401 ]             || fail "sin cabecera wompi_hash debe dar 401"
[ "$(post "$OK" "$(printf 'x%.0s' {1..64})")" = 401 ]                    || fail "hash inválido debe dar 401"
[ "$(post "$OK" "")" = 401 ]                                             || fail "hash vacío debe dar 401"
[ "$(post "${OK} " "$(sign "$OK")")" = 401 ]                             || fail "cuerpo alterado (1 espacio) debe dar 401"
! premium || fail "no debía ser Premium todavía"

BAD_AMT=$(body "$REF" 0.01 ExitosaAprobada)
[ "$(post "$BAD_AMT" "$(sign "$BAD_AMT")")" = 422 ] || fail "monto distinto al registrado debe dar 422"
! premium || fail "un monto erróneo activó Premium"

DECLINED=$(body "$REF" 4.99 Rechazada)
[ "$(post "$DECLINED" "$(sign "$DECLINED")")" = 200 ] || fail "resultado no aprobado se acusa con 200"
! premium || fail "un resultado distinto de ExitosaAprobada activó Premium"

LOWER=$(body "$REF" 4.99 exitosaaprobada)
[ "$(post "$LOWER" "$(sign "$LOWER")")" = 200 ] || fail "resultado en minúsculas se ignora con 200"
! premium || fail "ExitosaAprobada debe compararse de forma exacta"

UNKNOWN=$(body "LP-999999-noexiste" 4.99 ExitosaAprobada)
[ "$(post "$UNKNOWN" "$(sign "$UNKNOWN")")" = 404 ] || fail "referencia desconocida debe dar 404"

[ "$(post "$OK" "$(sign "$OK")")" = 200 ] || fail "webhook válido debe dar 200"
premium || fail "el usuario debe quedar Premium"
[ "$(post "$OK" "$(sign "$OK")")" = 200 ] || fail "el reintento de Wompi (replay) debe seguir dando 200"
premium || fail "tras el replay sigue siendo Premium"

[ "$(curl -fsS $B/c/$SLUG | grep -c 'id="ad-slot"' || true)" = 0 ] || fail "premium no debe ver anuncios"
echo "SMOKE OK"
