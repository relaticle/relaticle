#!/usr/bin/env bash
# Deploys the in-place Forge site. The deploy script in Forge is these two lines:
#
#   export PATH="$HOME/.local/bin:$PATH" COREPACK_ENABLE_DOWNLOAD_PROMPT=0
#   cd $FORGE_SITE_PATH && PHP=$FORGE_PHP PHP_FPM=$FORGE_PHP_FPM COMPOSER_BIN="$FORGE_COMPOSER" BRANCH=$FORGE_SITE_BRANCH bash bin/deploy.sh
#
# `bash bin/deploy.sh force` redeploys the live commit.

set -euo pipefail

PHP="${PHP:-php}"
PHP_FPM="${PHP_FPM:-}"
# Composer reads an exported COMPOSER as the path to composer.json.
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
PNPM="${PNPM:-pnpm}"
BRANCH="${BRANCH:-main}"

DEPLOYED_COMMIT_FILE="storage/framework/deployed-commit"
LOCK_FILE="storage/framework/deploy.lock"
DEPLOY_WINDOW_FILE="storage/framework/deploy.window"
DEPLOY_WINDOWS_LOG="storage/framework/deploy.windows"
LOCK_WAIT_SECONDS=900

# The audit scan ignores secret reads inside a window. It reads the finished ones from the log, because it runs every 5 minutes.
open_deploy_window() {
    if [[ "${1:-}" == "fresh" || ! -f "$DEPLOY_WINDOW_FILE" ]]; then
        date +%s > "$DEPLOY_WINDOW_FILE"
    fi

    trap 'close_deploy_window' EXIT
}

# shellcheck disable=SC2329
close_deploy_window() {
    local start
    start="$(cat "$DEPLOY_WINDOW_FILE" 2>/dev/null || true)"

    if [[ -n "$start" ]]; then
        echo "$start $(date +%s)" >> "$DEPLOY_WINDOWS_LOG"
        tail -n 50 "$DEPLOY_WINDOWS_LOG" > "$DEPLOY_WINDOWS_LOG.tmp" && mv "$DEPLOY_WINDOWS_LOG.tmp" "$DEPLOY_WINDOWS_LOG"
    fi

    rm -f "$DEPLOY_WINDOW_FILE"
}

fetch() {
    exec 9>"$LOCK_FILE"

    if ! flock -w "$LOCK_WAIT_SECONDS" 9; then
        echo "✗ another deploy held the lock for ${LOCK_WAIT_SECONDS}s" >&2
        exit 1
    fi

    open_deploy_window fresh

    # A changed .env changes deployed_state, so a settings-only change still releases.
    bash bin/fetch-production-secrets.sh

    git fetch --quiet origin "$BRANCH"

    local target
    target="$(git rev-parse FETCH_HEAD)"

    # A merge and its release each trigger a deploy, so the second usually finds its commit live.
    if [[ -f "$DEPLOYED_COMMIT_FILE" && "$(cat "$DEPLOYED_COMMIT_FILE")" == "$(deployed_state "$target")" ]]; then
        echo "✓ ${target:0:9} is already live"
        exit 0
    fi

    echo "→ Deploying ${target:0:9}"

    # scribe:generate rewrites a tracked view on every deploy, which a pull refuses to overwrite.
    git reset --quiet --hard "$target"

    # Re-executed so the release phase comes from the commit being deployed.
    exec bash bin/deploy.sh release
}

release() {
    local commit

    # fetch re-executes into this phase, which drops its trap, so the window carries over and closes here.
    open_deploy_window
    commit="$(git rev-parse HEAD)"

    $COMPOSER_BIN install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    $PHP artisan migrate --force

    # config:cache stores the release, so web requests and queue workers report under this commit.
    # Its view:cache also clears compiled views: Laravel reuses a stale plain-text mail copy after a template change (laravel/framework#62153).
    ( umask 077; SENTRY_RELEASE="$commit" $PHP artisan optimize )
    chmod 600 bootstrap/cache/config.php

    # PHP-FPM serves the previous code from opcache until this reload. Nothing slow may run
    # between the checkout and here, because until then old classes meet new views.
    reload_fpm

    $PHP artisan horizon:terminate
    $PHP artisan reverb:restart

    generate_api_docs
    build_assets
    $PHP artisan app:generate-sitemap

    deployed_state "$commit" > "$DEPLOYED_COMMIT_FILE"

    echo "✓ Deployed ${commit:0:9}"
}

deployed_state() {
    echo "$1 $(cksum < .env)"
}

reload_fpm() {
    if [[ -z "$PHP_FPM" ]]; then
        return
    fi

    (
        flock -w 10 8 || exit 1
        sudo -S service "$PHP_FPM" reload
    ) 8>/tmp/fpmlock
}

generate_api_docs() {
    # Scribe cannot read cached routes, so it is pointed at a cache file that does not exist.
    APP_ROUTES_CACHE=storage/framework/routes-uncached.php $PHP artisan scribe:generate --force
}

build_assets() {
    $PNPM install --frozen-lockfile

    rm -rf public/build-next public/build-previous
    $PNPM exec vite build --outDir public/build-next --emptyOutDir

    if [[ -d public/build ]]; then
        mv public/build public/build-previous
    fi

    mv public/build-next public/build
    rm -rf public/build-previous
}

if [[ ! -f artisan ]]; then
    echo "✗ run from the site root (artisan not found)" >&2
    exit 1
fi

case "${1:-fetch}" in
    fetch) fetch ;;
    force)
        rm -f "$DEPLOYED_COMMIT_FILE"
        fetch
        ;;
    release) release ;;
    *)
        echo "✗ unknown phase '${1}'" >&2
        exit 1
        ;;
esac

exit 0
