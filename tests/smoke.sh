#!/usr/bin/env bash
# Prueba de humo E2E por HTTP contra una instancia en ejecución.
# Uso: tests/smoke.sh http://127.0.0.1:8080 <WOMPI_EVENTS_SECRET>
set -euo pipefail
B=${1:?base url}; SECRET=${2:?events secret}
J=$(mktemp); trap 'rm -f "$J"' EXIT
fail(){ echo "FALLO: $*" >&2; exit 1; }
# `curl | grep -q` es una trampa con `pipefail`: grep cierra la tubería al primer acierto
# y curl termina con código 23. Se compara siempre sobre una variable.
has(){ case "$1" in *"$2"*) return 0;; *) return 1;; esac; }
tok(){ curl -fsS -b "$J" -c "$J" "$B/$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4; }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }

[ "$(curl -s $B/healthz.php)" = ok ] || fail healthz
[ "$(code -d 'email=a@b.co&password=12345678' $B/register.php)" = 403 ] || fail "registro sin CSRF debe dar 403"

T=$(tok register.php)
EMAIL="smoke$RANDOM@example.com"
[ "$(code -b "$J" -c "$J" --data-urlencode "_csrf=$T" --data-urlencode "email=$EMAIL" -d 'password=12345678' $B/register.php)" = 303 ] || fail registro

T=$(tok create.php)
[ "$(code -b "$J" -c "$J" --data-urlencode "_csrf=$T" -d 'template_id=1&your_name=Ana&start_date=2024-02-14' \
  --data-urlencode 'partner_name=<script>alert(1)</script>' --data-urlencode 'message=Hola {{your_name}}' $B/create.php)" = 303 ] || fail crear

SLUG=$(curl -fsS -b "$J" $B/dashboard.php | grep -o '/c/[a-z0-9]\{8\}' | head -1 | cut -d/ -f3)
[ -n "$SLUG" ] || fail "slug no encontrado"
PAGE=$(curl -fsS $B/c/$SLUG)
has "$PAGE" '&lt;script&gt;alert(1)' || fail "XSS no escapado"
has "$PAGE" 'id="ad-slot"' || fail "usuario gratis debe ver anuncios"

T=$(tok create.php)
R=$(curl -fsS -b "$J" -c "$J" --data-urlencode "_csrf=$T" -d 'template_id=2&your_name=A&partner_name=B&start_date=2024-02-14&message=x' $B/create.php)
has "$R" 'requiere Premium' || fail "plantilla premium debe bloquearse"

T=$(tok dashboard.php)
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -c "$J" --data-urlencode "_csrf=$T" $B/checkout_wompi.php)
REF=$(echo "$LOC" | grep -o 'reference=[^&]*' | cut -d= -f2); AMT=$(echo "$LOC" | grep -o 'amount-in-cents=[0-9]*' | cut -d= -f2)
[[ "$LOC" == https://checkout.wompi.co/* && -n "$REF" ]] || fail "redirección a Wompi"

TS=1700000000
ev(){ printf '{"event":"transaction.updated","data":{"transaction":{"id":"t1","status":"APPROVED","amount_in_cents":%s,"reference":"%s","currency":"COP"}},"timestamp":%s,"signature":{"properties":["transaction.id","transaction.status","transaction.amount_in_cents"],"checksum":"%s"}}' "$AMT" "$REF" "$TS" "$1"; }
[ "$(ev bad | curl -s -o /dev/null -w '%{http_code}' --data-binary @- $B/webhook_wompi.php)" = 401 ] || fail "firma mala debe dar 401"
SUM=$(printf 't1APPROVED%s%s%s' "$AMT" "$TS" "$SECRET" | sha256sum | cut -d' ' -f1)
[ "$(ev $SUM | curl -s -o /dev/null -w '%{http_code}' --data-binary @- $B/webhook_wompi.php)" = 200 ] || fail "webhook válido"

has "$(curl -fsS -b "$J" $B/dashboard.php)" 'Cuenta Premium' || fail "usuario debe quedar Premium"
! has "$(curl -fsS $B/c/$SLUG)" 'id="ad-slot"' || fail "premium no debe ver anuncios"
echo "SMOKE OK"
