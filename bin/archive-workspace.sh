#!/usr/bin/env bash
# Tears down what setup-workspace.sh provisioned: the Herd site, the
# workspace's testing databases (including parallel-test _test_N clones), and
# its Redis keys. Run from the workspace root. Best-effort: never fails the
# archive over a partially-provisioned workspace.

set -uo pipefail

if [[ ! -f artisan ]]; then
    echo "✗ run from the workspace root (artisan not found)" >&2
    exit 1
fi

FOLDER="$(basename "$PWD")"

unlink_site() {
    herd unsecure "$1" >/dev/null 2>&1 || true
    herd unlink "$1" >/dev/null 2>&1 || true
}

unlink_site "$FOLDER"

# An older setup may have linked the workspace name rather than the folder.
# Only touch that site when it provably points at THIS directory. The name
# can also belong to a different workspace's folder.
WORKSPACE_NAME="${CONDUCTOR_WORKSPACE_NAME:-}"
if [[ -n "$WORKSPACE_NAME" && "$WORKSPACE_NAME" != "$FOLDER" ]] &&
    herd links 2>/dev/null | grep -F " $WORKSPACE_NAME " | grep -qF "$PWD"; then
    unlink_site "$WORKSPACE_NAME"
fi

TEST_DB="relaticle_testing_$(echo "$FOLDER" | tr '-' '_')"
TEST_DB_USER="$(sed -n 's/^DB_USERNAME=//p' .env.testing 2>/dev/null | head -1)"
for db in $(psql -U "${TEST_DB_USER:-postgres}" -h 127.0.0.1 -d postgres -tAc "SELECT datname FROM pg_database WHERE datname='${TEST_DB}' OR datname ~ '^${TEST_DB}_test_[0-9]+$'" 2>/dev/null); do
    echo "→ Dropping ${db}"
    psql -U "${TEST_DB_USER:-postgres}" -h 127.0.0.1 -d postgres -c "DROP DATABASE IF EXISTS \"${db}\" WITH (FORCE)" || true
done

# The queue/cache Redis databases are set in .env (REDIS_DB / REDIS_CACHE_DB);
# --scan without -n only ever looks at db 0 and would silently delete nothing.
if command -v redis-cli >/dev/null 2>&1; then
    for db in $(sed -n 's/^REDIS_DB=//p; s/^REDIS_CACHE_DB=//p' .env 2>/dev/null | sort -u); do
        for pattern in "${FOLDER}_database_*" "${FOLDER}_cache_*" "${FOLDER}_horizon:*"; do
            redis-cli -n "$db" --scan --pattern "$pattern" | xargs -n 100 redis-cli -n "$db" del >/dev/null 2>&1 || true
        done
    done
fi

echo "✓ Workspace '${FOLDER}' torn down"
