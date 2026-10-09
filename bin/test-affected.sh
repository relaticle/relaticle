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

CLASSES="$(printf '%s\n' "$LISTING" | sed -nE 's/^ - P\\(Tests\\[^:]+)::.*/\1/p' | sort -u)"

if [[ -z "$CLASSES" ]]; then
    echo "✓ No test in the baseline reaches this change"
    exit 0
fi

COUNT="$(printf '%s\n' "$CLASSES" | wc -l | tr -d ' ')"
FILES="$(printf '%s\n' "$CLASSES" | sed -E 's/^Tests/tests/; s#\\#/#g; s/$/.php/')"

echo "→ ${COUNT} affected test files"
printf '%s\n' "$FILES"

if [[ "${1:-}" == "--plan" ]]; then
    exit 0
fi

if (( COUNT > MAX_FILES )); then
    echo "✗ ${COUNT} files is past the limit of ${MAX_FILES}. Run the files you touched and let CI run the rest." >&2
    exit 1
fi

if (( COUNT == 1 )); then
    exec vendor/bin/pest --compact --no-tia "$FILES"
fi

FILTER="($(printf '%s\n' "$CLASSES" | sed -E 's/\\/\\\\/g' | paste -sd'|' -))::"

exec vendor/bin/pest --compact --no-tia --parallel --processes=4 --filter="$FILTER"
