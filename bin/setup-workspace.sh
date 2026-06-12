#!/usr/bin/env bash
# Bootstrap a Polyscope workspace: refresh dependencies and point .env at
# the workspace's own hostname.
#
# Polyscope creates each workspace as a copy-on-write clone of the base repo
# (vendor/, node_modules/, and .env are copied). The database stays shared
# across workspaces. We rewrite APP_URL and SESSION_DOMAIN so absolute URLs
# and session cookies match `<workspace>.test`, and blank APP_PANEL_DOMAIN
# so the app panel serves path-based at `<workspace>.test/app` — the copied
# `app.relaticle.test` value would route to the base checkout, not the clone.
#
# Mac-only: uses BSD sed (`sed -i ''`). Polyscope itself is macOS-only.

set -euo pipefail

WORKSPACE="$(basename "$PWD")"
WORKSPACE_HOST="${WORKSPACE}.test"

if [[ ! -f .env ]]; then
    echo "✗ .env not found in $(pwd)" >&2
    exit 1
fi

echo "→ Refreshing PHP dependencies"
composer install --no-interaction --prefer-dist

echo "→ Refreshing JS dependencies"
npm ci

echo "→ Pointing .env at ${WORKSPACE_HOST}"
sed -i '' "s|^APP_URL=.*|APP_URL=https://${WORKSPACE_HOST}|" .env
sed -i '' "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.${WORKSPACE_HOST}|" .env
sed -i '' "s|^APP_PANEL_DOMAIN=.*|APP_PANEL_DOMAIN=|" .env

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction

echo "→ Building frontend assets"
npm run build

echo "✓ Workspace ready: https://${WORKSPACE_HOST} (app panel at /app)"
