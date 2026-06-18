#!/usr/bin/env bash
# Bootstrap a parallel workspace (Polyscope or Conductor): install dependencies
# and point .env at the workspace's own hostname. Both orchestrators copy a
# working `.env` from the base checkout and run this from the workspace dir.
#
# Copy-on-write parity:
# - Polyscope CoW-clones the whole base repo (vendor/, node_modules/, .env), so
#   the installs below are fast refreshes.
# - Conductor's git worktree copies only `.env`. To match the feel we APFS-clone
#   vendor/ and node_modules/ from the base checkout first (`cp -c` = clonefile:
#   near-instant, copy-on-write, each workspace diverges on its own writes),
#   turning the installs below into the same fast refreshes.
#   NOTE: we use `npm install` (incremental), NOT `npm ci` — `npm ci` deletes
#   node_modules before installing, which would throw away the clone.
#
# The database stays shared across workspaces. We rewrite APP_URL and
# SESSION_DOMAIN so absolute URLs and session cookies match `<workspace>.test`,
# and blank APP_PANEL_DOMAIN and SYSADMIN_DOMAIN so both panels serve path-based
# at `<workspace>.test/app` and `<workspace>.test/sysadmin` — the copied
# `*.relaticle.test` values would route to the base checkout, not the workspace.
#
# Mac-only: uses BSD sed (`sed -i ''`) and APFS clonefile. Both orchestrators
# are macOS-only.

set -euo pipefail

WORKSPACE="$(basename "$PWD")"
WORKSPACE_HOST="${WORKSPACE}.test"

if [[ ! -f .env ]]; then
    echo "✗ .env not found in $(pwd)" >&2
    exit 1
fi

# Base checkout to clone dependencies from. Conductor sets CONDUCTOR_ROOT_PATH;
# fall back to the git common dir's parent for Polyscope / manual runs.
BASE="${CONDUCTOR_ROOT_PATH:-$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")}"

# APFS copy-on-write seed: when a dependency dir is missing (Conductor worktree),
# clone it from the base checkout via clonefile so the install below is a fast
# refresh, not a cold install. No-op when already present (Polyscope) or when
# this checkout IS the base (run from the root).
clone_from_base() {
    local dir="$1"
    if [[ -d "$dir" || "$BASE" == "$PWD" || ! -d "$BASE/$dir" ]]; then
        return 0
    fi
    echo "→ Cloning ${dir}/ from base (APFS copy-on-write)"
    cp -c -R "$BASE/$dir" "$dir"
}

clone_from_base vendor
clone_from_base node_modules

echo "→ Refreshing PHP dependencies"
composer install --no-interaction --prefer-dist

echo "→ Refreshing JS dependencies"
npm install --no-audit --no-fund

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
