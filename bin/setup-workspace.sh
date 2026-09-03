#!/usr/bin/env bash
# Provisions a parallel workspace (Paseo, Polyscope, or Conductor): Herd site, .env
# rewrites, per-workspace testing database, dependencies, and a frontend build.
# Run from the workspace root. Idempotent: safe to re-run.
#
# Isolation keys use the folder basename, never CONDUCTOR_WORKSPACE_NAME.
# They add the repository name only when another project already owns that
# workspace name.
#
# The dev database (relaticle_app) stays shared across workspaces. We rewrite
# APP_URL and SESSION_DOMAIN so absolute URLs and session cookies match
# `<workspace>.test`, and blank APP_PANEL_DOMAIN and SYSADMIN_DOMAIN so both
# panels serve path-based at `<workspace>.test/app` and `/sysadmin`. The
# copied `*.relaticle.test` values would route to the base checkout.
#
# Mac-only: uses BSD sed (`sed -i ''`). Both orchestrators are macOS-only.

set -euo pipefail

if [[ ! -f artisan ]]; then
    echo "✗ run from the workspace root (artisan not found)" >&2
    exit 1
fi

FOLDER="$(basename "$PWD")"
ROOT="${PASEO_SOURCE_CHECKOUT_PATH:-$HOME/Herd/relaticle}"

echo "→ Provisioning workspace '${FOLDER}' (root: ${ROOT})"

if [[ ! -f .env ]]; then
    cp "$ROOT/.env" .env
fi

site_is_linked() {
    herd links 2>/dev/null | grep -Fq "| $1 "
}

site_points_here() {
    herd links 2>/dev/null | grep -F "| $1 " | grep -qF "| $PWD "
}

SITE_NAME="${WORKSPACE_SITE_NAME:-$FOLDER}"
if [[ "$SITE_NAME" == "$FOLDER" ]] && site_is_linked "$SITE_NAME" && ! site_points_here "$SITE_NAME"; then
    SITE_NAME="$(basename "$ROOT")-$FOLDER"
fi

if site_is_linked "$SITE_NAME" && ! site_points_here "$SITE_NAME"; then
    echo "✗ Herd site '${SITE_NAME}' already belongs to another directory" >&2
    exit 1
fi

sed -i '' "/^WORKSPACE_SITE_NAME=/d" .env
echo "WORKSPACE_SITE_NAME=${SITE_NAME}" >> .env

echo "→ Herd site"
if ! site_points_here "$SITE_NAME"; then
    herd link "$SITE_NAME"
fi
herd secure "$SITE_NAME"

echo "→ Pointing .env at ${SITE_NAME}.test"
sed -i '' "s|^APP_URL=.*|APP_URL=https://${SITE_NAME}.test|" .env
sed -i '' "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.${SITE_NAME}.test|" .env
sed -i '' "s|^APP_PANEL_DOMAIN=.*|APP_PANEL_DOMAIN=|" .env
sed -i '' "s|^SYSADMIN_DOMAIN=.*|SYSADMIN_DOMAIN=|" .env

# All checkouts share one local Redis. Without per-workspace prefixes, every
# checkout resolves the same default key prefix (APP_NAME-derived), so another
# workspace's Horizon worker consumes THIS workspace's jobs with foreign code,
# and cache entries leak across branches.
echo "→ Isolating Redis keys for ${SITE_NAME}"
sed -i '' "/^REDIS_PREFIX=/d; /^CACHE_PREFIX=/d; /^HORIZON_PREFIX=/d" .env
{
    echo ""
    echo "REDIS_PREFIX=${SITE_NAME}_database_"
    echo "CACHE_PREFIX=${SITE_NAME}_cache_"
    echo "HORIZON_PREFIX=${SITE_NAME}_horizon:"
} >> .env

# Each workspace gets its own testing database: two suites sharing one DB
# deadlock, and a migration on one branch breaks the other's schema mid-run.
# phpunit.xml deliberately omits DB_DATABASE so this .env.testing value wins;
# skip-worktree keeps the tracked file's rewrite out of every diff.
TEST_DB="relaticle_testing_$(echo "$SITE_NAME" | tr '-' '_')"
echo "→ Testing database ${TEST_DB}"
sed -i '' "s|^DB_DATABASE=.*|DB_DATABASE=${TEST_DB}|" .env.testing
git update-index --skip-worktree .env.testing
TEST_DB_USER="$(sed -n 's/^DB_USERNAME=//p' .env.testing | head -1)"
if ! psql -U "${TEST_DB_USER:-postgres}" -h 127.0.0.1 -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'" | grep -q 1; then
    psql -U "${TEST_DB_USER:-postgres}" -h 127.0.0.1 -d postgres -c "CREATE DATABASE \"${TEST_DB}\""
fi

echo "→ PHP dependencies (vendor cloned from root checkout)"
if [[ ! -e vendor && -d "$ROOT/vendor" ]] && [[ ! "$ROOT" -ef "$PWD" ]]; then
    cp -Rc "$ROOT/vendor" vendor 2>/dev/null || cp -R "$ROOT/vendor" vendor
fi
composer install --no-interaction --prefer-dist

echo "→ JS dependencies"
if [[ -f pnpm-lock.yaml ]]; then
    PNPM_VERSION="$(node -p "require('./package.json').packageManager.split('@').pop()")"
    CI=true npx --yes "pnpm@${PNPM_VERSION}" install --frozen-lockfile --prefer-offline
    JS_BUILD_COMMAND=(npx --yes "pnpm@${PNPM_VERSION}" run build)
elif [[ -f package-lock.json ]]; then
    npm ci
    JS_BUILD_COMMAND=(npm run build)
else
    echo "✗ no supported JS lockfile found" >&2
    exit 1
fi

# Tokens must verify across every checkout because the dev database is shared:
# copy the root's keys instead of minting fresh ones.
if [[ ! -f storage/oauth-private.key ]]; then
    cp "$ROOT/storage/oauth-private.key" "$ROOT/storage/oauth-public.key" storage/ 2>/dev/null ||
        php artisan passport:keys --no-interaction
fi

[[ -e public/storage ]] || php artisan storage:link

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction

echo "→ Building frontend assets"
"${JS_BUILD_COMMAND[@]}"

echo "✓ Workspace ready: https://${SITE_NAME}.test (app panel at /app, sysadmin at /sysadmin)"
