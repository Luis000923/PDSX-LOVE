#!/usr/bin/env bash
# Se ejecuta EN EL SERVIDOR (lo envía el workflow CD por SSH). Variables: DEPLOY_PATH IMAGE TAG GHCR_USER GHCR_TOKEN
set -euo pipefail
cd "$DEPLOY_PATH"
[ -f .env ] || { echo "Falta $DEPLOY_PATH/.env"; exit 1; }
DC="docker compose -f docker-compose.prod.yml"

echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin
prev=$(cat .current_tag 2>/dev/null || true)

export IMAGE TAG
$DC pull
if $DC up -d --wait --wait-timeout 90; then
  echo "$TAG" > .current_tag
  docker image prune -f >/dev/null
  echo "Desplegado $IMAGE:$TAG"
else
  echo "::error::Despliegue fallido; volviendo a ${prev:-<ninguna>}" >&2
  $DC logs --tail 50 web || true
  if [ -n "$prev" ]; then TAG=$prev $DC up -d --wait --wait-timeout 90; fi
  exit 1
fi
docker logout ghcr.io
