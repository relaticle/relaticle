#!/usr/bin/env bash
# Provisions a parallel workspace (Polyscope or Conductor): Herd site, .env
# rewrites, per-workspace testing database, dependencies, and a frontend build.
# Run from the workspace root. Idempotent: safe to re-run.
#
# Everything is keyed off the folder basename, never CONDUCTOR_WORKSPACE_NAME:
# Conductor renames workspaces after setup, which would strand the Herd site
# and .env under the old name. The folder never changes.
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
ROOT="${CONDUCTOR_ROOT_PATH:-$HOME/Herd/relaticle}"

echo "→ Provisioning workspace '${FOLDER}' (root: ${ROOT})"

if [[ ! -f .env ]]; then
    cp "$ROOT/.env" .env
fi

echo "→ Herd site"
herd link "$FOLDER"
herd secure "$FOLDER"

echo "→ Pointing .env at ${FOLDER}.test"
sed -i '' "s|^APP_URL=.*|APP_URL=https://${FOLDER}.test|" .env
sed -i '' "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.${FOLDER}.test|" .env
sed -i '' "s|^APP_PANEL_DOMAIN=.*|APP_PANEL_DOMAIN=|" .env
sed -i '' "s|^SYSADMIN_DOMAIN=.*|SYSADMIN_DOMAIN=|" .env

# All checkouts share one local Redis. Without per-workspace prefixes, every
# checkout resolves the same default key prefix (APP_NAME-derived), so another
# workspace's Horizon worker consumes THIS workspace's jobs with foreign code,
# and cache entries leak across branches.
echo "→ Isolating Redis keys for ${FOLDER}"
sed -i '' "/^REDIS_PREFIX=/d; /^CACHE_PREFIX=/d; /^HORIZON_PREFIX=/d" .env
{
    echo ""
    echo "REDIS_PREFIX=${FOLDER}_database_"
    echo "CACHE_PREFIX=${FOLDER}_cache_"
    echo "HORIZON_PREFIX=${FOLDER}_horizon:"
} >> .env

# Each workspace gets its own testing database: two suites sharing one DB
# deadlock, and a migration on one branch breaks the other's schema mid-run.
# phpunit.xml deliberately omits DB_DATABASE so this .env.testing value wins;
# skip-worktree keeps the tracked file's rewrite out of every diff.
TEST_DB="relaticle_testing_$(echo "$FOLDER" | tr '-' '_')"
echo "→ Testing database ${TEST_DB}"
sed -i '' "s|^DB_DATABASE=.*|DB_DATABASE=${TEST_DB}|" .env.testing
git update-index --skip-worktree .env.testing
TEST_DB_USER="$(sed -n 's/^DB_USERNAME=//p' .env.testing | head -1)"
TEST_DB_HOST="$(sed -n 's/^DB_HOST=//p' .env.testing | head -1)"
TEST_DB_PORT="$(sed -n 's/^DB_PORT=//p' .env.testing | head -1)"
TEST_DB_PASSWORD="$(sed -n 's/^DB_PASSWORD=//p' .env.testing | head -1)"
# Homebrew libpq is keg-only, so `psql` is missing from PATH (exit 127 in
# Cursor worktree setup). Herd's PHP already has pdo_pgsql.
SETUP_TEST_DB="$TEST_DB" \
    SETUP_TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}" \
    SETUP_TEST_DB_PORT="${TEST_DB_PORT:-5432}" \
    SETUP_TEST_DB_USER="${TEST_DB_USER:-postgres}" \
    SETUP_TEST_DB_PASSWORD="${TEST_DB_PASSWORD:-}" \
    php <<'PHP'
<?php

declare(strict_types=1);

$db = getenv('SETUP_TEST_DB');
if ($db === false || $db === '' || preg_match('/[^A-Za-z0-9_]/', $db) === 1) {
    fwrite(STDERR, "✗ invalid testing database name\n");
    exit(1);
}

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=postgres',
        getenv('SETUP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('SETUP_TEST_DB_PORT') ?: '5432',
    ),
    getenv('SETUP_TEST_DB_USER') ?: 'postgres',
    getenv('SETUP_TEST_DB_PASSWORD') !== false ? (string) getenv('SETUP_TEST_DB_PASSWORD') : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$exists = $pdo->query('SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($db))->fetchColumn();
if ($exists !== false) {
    exit(0);
}

$pdo->exec(sprintf('CREATE DATABASE "%s"', $db));
PHP

echo "→ PHP dependencies (vendor cloned from root checkout)"
if [[ ! -e vendor && -d "$ROOT/vendor" ]] && [[ ! "$ROOT" -ef "$PWD" ]]; then
    cp -Rc "$ROOT/vendor" vendor 2>/dev/null || cp -R "$ROOT/vendor" vendor
fi
composer install --no-interaction --prefer-dist

echo "→ JS dependencies"
pnpm install --prefer-offline

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
pnpm run build

echo "✓ Workspace ready: https://${FOLDER}.test (app panel at /app, sysadmin at /sysadmin)"
