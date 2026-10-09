#!/usr/bin/env bash
# Runs the tests the branch's changes reach. Pest picks them from the TIA baseline, and a
# plain parallel run executes them: a TIA run records coverage, at 2.5x the CPU.
#
#   bin/test-affected.sh          pick and run
#   bin/test-affected.sh --plan   list the picked test files and stop

set -euo pipefail

if [[ ! -f artisan ]]; then
    echo "✗ run from the workspace root (artisan not found)" >&2
    exit 1
fi

# Past this many files the change reaches most of the suite, or Pest found no usable
# baseline and listed every test. CI runs the whole suite on the pushed commit.
MAX_FILES=60

git fetch --quiet origin main

LISTING="$(vendor/bin/pest --tia --baselined --filtered --refetch --list-tests 2>&1)" || {
    echo "$LISTING" >&2
    exit 1
}

# The cached config and routes load once per process, so coverage ties them to a few
# tests. JavaScript and CSS reach only the Browser suite, which CI runs.
UNPLACED="$({
    git diff --name-only "$(git merge-base HEAD origin/main)"
    git ls-files --others --exclude-standard
} | grep -E '^(config|routes|bootstrap)/|^(resources|packages/[^/]+/resources)/(js|css)/' || true)"

if [[ -n "$UNPLACED" ]]; then
    echo "! Coverage cannot place these files. Pick their tests by hand:"
    printf '%s\n' "$UNPLACED" | sed 's/^/  /'
fi

CLASSES="$(printf '%s\n' "$LISTING" | sed -nE 's/^ - P\\(Tests\\[^:]+)::.*/\1/p' | sort -u)"

if [[ -z "$CLASSES" ]]; then
    echo "✓ No test in the baseline reaches this change"
    exit 0
fi

COUNT="$(printf '%s\n' "$CLASSES" | wc -l | tr -d ' ')"
FILES="$(printf '%s\n' "$CLASSES" | sed -E 's/^Tests/tests/; s#\\#/#g; s/$/.php/')"

echo "→ ${COUNT} affected test files"

if [[ "${1:-}" == "--plan" ]]; then
    printf '%s\n' "$FILES"
    exit 0
fi

if (( COUNT > MAX_FILES )); then
    echo "✗ Past the limit of ${MAX_FILES}: the change reaches most of the suite, or no baseline matches this checkout." >&2
    echo "  Run the test files you touched and let CI run the rest. --plan lists all ${COUNT}." >&2
    exit 1
fi

printf '%s\n' "$FILES"

if (( COUNT == 1 )); then
    exec vendor/bin/pest --compact --no-tia "$FILES"
fi

FILTER="($(printf '%s\n' "$CLASSES" | sed -E 's/\\/\\\\/g' | paste -sd'|' -))::"

exec vendor/bin/pest --compact --no-tia --parallel --processes=4 --filter="$FILTER"
