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
VENDOR_BUILD_DIR="storage/framework/deploy-build"
SCHEDULE_PAUSED_FILE="storage/framework/deploy.schedule-paused"
COMPILED_VIEWS_ROOT="storage/framework/views"
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

    resume_schedule
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

    build_vendor "$target"
    pause_schedule

    # scribe:generate rewrites a tracked view on every deploy, which a pull refuses to overwrite.
    git reset --quiet --hard "$target"
    swap_vendor

    # Re-executed so the release phase comes from the commit being deployed.
    VENDOR_PREPARED=1 exec bash bin/deploy.sh release
}

# Requests and scheduled commands load vendor while composer rewrites it in place, so the next one is built beside it.
build_vendor() {
    rm -rf "$VENDOR_BUILD_DIR"
    mkdir -p "$VENDOR_BUILD_DIR"
    git archive "$1" | tar -x -C "$VENDOR_BUILD_DIR"
    cp -a vendor "$VENDOR_BUILD_DIR/vendor"

    $COMPOSER_BIN install --working-dir="$VENDOR_BUILD_DIR" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
}

swap_vendor() {
    rm -rf vendor-previous
    mv vendor vendor-previous
    mv "$VENDOR_BUILD_DIR/vendor" vendor
}

pause_schedule() {
    touch "$SCHEDULE_PAUSED_FILE"
    $PHP artisan schedule:pause
}

resume_schedule() {
    if [[ ! -f "$SCHEDULE_PAUSED_FILE" ]]; then
        return
    fi

    if $PHP artisan schedule:resume; then
        rm -f "$SCHEDULE_PAUSED_FILE"
    else
        echo "✗ the scheduler is still paused: run php artisan schedule:resume" >&2
    fi
}

release() {
    local commit

    # fetch re-executes into this phase, which drops its trap, so the window carries over and closes here.
    open_deploy_window
    commit="$(git rev-parse HEAD)"

    # A deploy started by a script from before the vendor build has no prepared vendor.
    if [[ "${VENDOR_PREPARED:-}" == "1" ]]; then
        $COMPOSER_BIN run-script post-autoload-dump --no-interaction
    else
        $COMPOSER_BIN install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    fi

    $PHP artisan migrate --force

    # Each release compiles into its own folder: view:cache empties the folder it compiles into, and the
    # running workers still render from the previous one. A fresh folder also avoids laravel/framework#62153.
    local compiled_views="$PWD/$COMPILED_VIEWS_ROOT/release-${commit:0:12}"
    mkdir -p "$compiled_views"

    # config:cache stores the release and the views folder. Until PHP-FPM reloads, it keeps the old classes in
    # opcache, so it must keep the old compiled views too instead of recompiling templates the checkout changed.
    ( umask 077; SENTRY_RELEASE="$commit" VIEW_COMPILED_PATH="$compiled_views" VIEW_CHECK_CACHE_TIMESTAMPS=false $PHP artisan optimize )
    chmod 600 bootstrap/cache/config.php

    # PHP-FPM serves the previous code from opcache until this reload. Nothing slow may run
    # between the checkout and here, because until then old classes meet new views.
    reload_fpm
    resume_schedule

    $PHP artisan horizon:terminate
    $PHP artisan reverb:restart

    generate_api_docs
    build_assets
    $PHP artisan app:generate-sitemap

    prune_previous_release

    deployed_state "$commit" > "$DEPLOYED_COMMIT_FILE"

    echo "✓ Deployed ${commit:0:9}"
}

# Keeps the previous release's compiled views for workers still finishing a job under the old config.
prune_previous_release() {
    rm -rf vendor-previous "$VENDOR_BUILD_DIR"
    ls -1dt "$COMPILED_VIEWS_ROOT"/release-* 2> /dev/null | tail -n +3 | while read -r folder; do rm -rf "$folder"; done || true
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
    local staged=storage/framework/cache/vite-next published=storage/framework/cache/vite-published
    local prune=false

    $PNPM install --frozen-lockfile
    $PNPM exec vite build --outDir "$staged" --emptyOutDir
    test -s "$staged/manifest.json"

    # Keep the last build's chunks for open tabs. Retry an identical build without aging them out.
    if cmp -s "$published/manifest.json" public/build/manifest.json && ! cmp -s "$staged/manifest.json" public/build/manifest.json; then
        prune=true
    fi

    rsync -a --exclude=/manifest.json "$staged/" public/build/
    rsync -a --checksum "$staged/manifest.json" public/build/
    if "$prune"; then
        rsync -a --delete-after "$staged/assets/" "$published/assets/" public/build/assets/
    fi
    rm -rf "$published"
    mv "$staged" "$published"
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
