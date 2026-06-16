#!/usr/bin/env bash
# Bootstrap a parallel workspace (Polyscope or Conductor): install dependencies
# and point .env at the workspace's own hostname. Both orchestrators copy a
# working `.env` from the base checkout and run this from the workspace dir.
#
# - Polyscope copy-on-write clones the base repo (vendor/, node_modules/, .env
#   copied), so composer/npm below are fast refreshes.
# - Conductor creates a git worktree and copies only `.env`, so composer/npm
#   below do full installs of the absent vendor/ and node_modules/.
#
# The database stays shared across workspaces. We rewrite APP_URL and
# SESSION_DOMAIN so absolute URLs and session cookies match `<workspace>.test`,
# and blank APP_PANEL_DOMAIN and SYSADMIN_DOMAIN so both panels serve path-based
# at `<workspace>.test/app` and `<workspace>.test/sysadmin` — the copied
# `*.relaticle.test` values would route to the base checkout, not the workspace.
#
# Mac-only: uses BSD sed (`sed -i ''`). Both orchestrators are macOS-only.

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
sed -i '' "s|^SYSADMIN_DOMAIN=.*|SYSADMIN_DOMAIN=|" .env

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction

echo "→ Building frontend assets"
npm run build

echo "✓ Workspace ready: https://${WORKSPACE_HOST} (app panel at /app, sysadmin at /sysadmin)"
