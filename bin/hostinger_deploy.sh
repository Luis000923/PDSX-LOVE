#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

if [[ ! -d .git ]]; then
    echo "Error: $APP_DIR no es un repositorio Git." >&2
    exit 1
fi

if [[ ! -f .env ]]; then
    echo "Error: falta $APP_DIR/.env. Créalo antes de desplegar." >&2
    exit 1
fi

echo "Sincronizando la aplicación desde origin/main..."
git sparse-checkout disable 2>/dev/null || true
git fetch --prune origin main
git reset --hard origin/main
git clean -fd -e .env -e storage/ -e public/uploads/ -e public/assets/thumbs/

mkdir -p storage/user_html storage/user_templates public/uploads public/assets/thumbs
chmod 600 .env

if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
else
    echo "Advertencia: Composer no está instalado; se omitió composer install." >&2
fi

echo "Despliegue terminado en $APP_DIR"
echo "Document Root: $APP_DIR/public"