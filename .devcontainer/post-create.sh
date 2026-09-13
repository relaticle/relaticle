#!/usr/bin/env bash
# Prepara la aplicación la primera vez que se crea el contenedor.
# Es idempotente: se puede relanzar sin romper nada ni borrar datos.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "Esperando a PostgreSQL..."
until pg_isready -h 127.0.0.1 -p 5432 -q; do
    sleep 1
done

if [ ! -f .env ]; then
    cp .env.example .env

    # APP_URL debe coincidir con la URL del navegador: RedirectToPrimaryHost
    # redirige cualquier otro host y getAppUrl() construye los enlaces con ella.
    if [ -n "${CODESPACE_NAME:-}" ]; then
        app_url="https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
    else
        app_url="http://localhost:8000"
    fi
    sed -i "s|^APP_URL=.*|APP_URL=${app_url}|" .env

    # CRM interno de Crabdev: sin login social, documentación ni datos de demo.
    sed -i 's|^APP_NAME=.*|APP_NAME="Crabdev CRM"|' .env
    cat >> .env <<'ENV'

RELATICLE_FEATURE_SOCIAL_AUTH=false
RELATICLE_FEATURE_DOCUMENTATION=false
RELATICLE_FEATURE_ONBOARD_SEED=false
ENV
fi

composer install --no-interaction --prefer-dist

# Sin esto pnpm crea .pnpm-store/ dentro del proyecto, porque /workspaces está
# en otro sistema de ficheros que el home del contenedor.
pnpm config set store-dir "$HOME/.local/share/pnpm/store"
pnpm install --frozen-lockfile

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --ansi
[ -f storage/oauth-private.key ] || php artisan passport:keys --no-interaction

php artisan migrate --force
php artisan storage:link --force
pnpm run build

echo
echo "Listo. Arranca con: composer dev   (app en el puerto 8000)"
echo "Tests:              php artisan test"
