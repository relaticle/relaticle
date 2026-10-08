#!/usr/bin/env bash
# Deploys the in-place Forge site. The deploy script in Forge is these two lines:
#
#   export PATH="$HOME/.local/bin:$PATH" COREPACK_ENABLE_DOWNLOAD_PROMPT=0
#   cd $FORGE_SITE_PATH && PHP=$FORGE_PHP PHP_FPM=$FORGE_PHP_FPM COMPOSER="$FORGE_COMPOSER" BRANCH=$FORGE_SITE_BRANCH bash bin/deploy.sh
#
# Every merge to main and every release triggers a deploy, so two often arrive
# within a minute. A lock runs them one at a time, and a commit that is already
# live is skipped unless .env changed since. `bash bin/deploy.sh force` redeploys
# the live commit.
#
# PHP-FPM keeps the previous code in opcache until it reloads, so the reload is
# the moment the site switches. Dependencies, migrations and the config, route
# and view caches are in place before it. Nothing slow runs between the checkout
# and the reload, because until then old classes meet new views.
#
# The fetch phase re-executes this file after the checkout moves, so a change to
# the release phase applies to the deploy that ships it.

set -euo pipefail

PHP="${PHP:-php}"
PHP_FPM="${PHP_FPM:-}"
COMPOSER="${COMPOSER:-composer}"
PNPM="${PNPM:-pnpm}"
BRANCH="${BRANCH:-main}"

DEPLOYED_COMMIT_FILE="storage/framework/deployed-commit"
LOCK_FILE="storage/framework/deploy.lock"
LOCK_WAIT_SECONDS=900

fetch() {
    exec 9>"$LOCK_FILE"

    if ! flock -w "$LOCK_WAIT_SECONDS" 9; then
        echo "✗ another deploy held the lock for ${LOCK_WAIT_SECONDS}s" >&2
        exit 1
    fi

    git fetch --quiet origin "$BRANCH"

    local target
    target="$(git rev-parse FETCH_HEAD)"

    if [[ -f "$DEPLOYED_COMMIT_FILE" && "$(cat "$DEPLOYED_COMMIT_FILE")" == "$(deployed_state "$target")" ]]; then
        echo "✓ ${target:0:9} is already live"
        exit 0
    fi

    echo "→ Deploying ${target:0:9}"

    # scribe:generate rewrites a tracked view on every deploy, which a pull refuses to overwrite.
    git reset --quiet --hard "$target"

    exec bash bin/deploy.sh release
}

release() {
    local commit
    commit="$(git rev-parse HEAD)"

    $COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    $PHP artisan migrate --force

    # config:cache stores the release, so web requests and queue workers report under this commit.
    SENTRY_RELEASE="$commit" $PHP artisan optimize

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
