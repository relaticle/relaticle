#!/usr/bin/env bash
# Decides whether the manual-testing skill should run on the current diff.
# Outputs a single line: "SKIP <reason>" or "RUN <summary>".
# Exit code: 0 = SKIP, 1 = RUN.
#
# Diff source priority:
#   1. $MANUAL_TESTING_TEST_DIFF (newline-separated paths) — for testing.
#   2. `git diff HEAD --name-only` — real diff in the working tree.
#
# Branch override for testing:
#   $MANUAL_TESTING_TEST_BRANCH — when set, used instead of `git rev-parse`.

set -euo pipefail

# Hard env-var skip
if [[ "${MANUAL_TESTING_SKIP:-0}" == "1" ]]; then
    echo "SKIP env-var-override"
    exit 0
fi

# Branch check — never run on main / master / release/*
if [[ "${MANUAL_TESTING_TEST_BRANCH+set}" == "set" ]]; then
    branch="$MANUAL_TESTING_TEST_BRANCH"
else
    branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
fi
case "$branch" in
    main|master|release/*)
        echo "SKIP protected-branch:$branch"
        exit 0
        ;;
esac

# Acquire the diff path list
# Use MANUAL_TESTING_TEST_DIFF when the variable is explicitly set (even to ""),
# falling back to the real git diff only when the variable is unset.
if [[ "${MANUAL_TESTING_TEST_DIFF+set}" == "set" ]]; then
    diff_paths="$MANUAL_TESTING_TEST_DIFF"
else
    diff_paths=$(git diff HEAD --name-only 2>/dev/null || echo "")
fi

# Empty diff → skip
if [[ -z "$diff_paths" ]]; then
    echo "SKIP empty-diff"
    exit 0
fi

# Test paths individually; if every path matches a "trivial" pattern, skip.
all_trivial=1
while IFS= read -r path; do
    [[ -z "$path" ]] && continue
    case "$path" in
        *.md|*.txt|*.lock|*.gitignore|composer.lock|package-lock.json|yarn.lock)
            ;;
        tests/*)
            ;;
        *)
            all_trivial=0
            break
            ;;
    esac
done <<< "$diff_paths"

if [[ $all_trivial -eq 1 ]]; then
    echo "SKIP trivial-paths-only"
    exit 0
fi

# Otherwise run, with a one-line summary of the diff size
file_count=$(echo "$diff_paths" | grep -c . || echo 0)
echo "RUN files=$file_count"
exit 1
