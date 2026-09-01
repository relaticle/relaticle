#!/usr/bin/env bash
# Runs this workspace's queue worker (Horizon) and restarts it when PHP changes.
#
# Horizon holds every class in memory for the life of a worker, so a worker
# started before an edit keeps executing the old code until it is terminated,
# chat prompts and job logic silently run a version you already changed.
# watchexec sends SIGTERM on a PHP change, which Horizon's master handles as a
# graceful shutdown, then relaunches. Boot is the entire cost (~6 CPU-seconds,
# workers serving again after ~3s), so the debounce matters more than the
# watch list: one restart per burst of edits, not one per keystroke.
#
# Run from anywhere; the script resolves the workspace root itself.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

if ! command -v watchexec >/dev/null 2>&1; then
    echo "[queue] watchexec not found: workers will hold stale code until you restart them." >&2
    exec php artisan horizon
fi

exec watchexec --restart --debounce 3s --exts php \
    --watch app --watch packages --watch config --watch routes \
    --stop-signal SIGTERM --stop-timeout 20s \
    -- php artisan horizon
