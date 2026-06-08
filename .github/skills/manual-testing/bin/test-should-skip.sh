#!/usr/bin/env bash
# Test fixture for should-skip.sh
# Each test sets up a synthetic diff via env var, runs should-skip, asserts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$SCRIPT_DIR/should-skip.sh"

assert_eq() {
    if [[ "$1" != "$2" ]]; then
        echo "FAIL: expected '$2', got '$1'"
        exit 1
    fi
    echo "OK: $3"
}

# Test 1: empty diff → SKIP (exit 0, reason "empty")
out=$(MANUAL_TESTING_TEST_DIFF="" "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "empty diff returns SKIP"

# Test 2: doc-only diff → SKIP
out=$(MANUAL_TESTING_TEST_DIFF=$'README.md\nCHANGELOG.md' "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "doc-only diff returns SKIP"

# Test 3: lockfile-only diff → SKIP
out=$(MANUAL_TESTING_TEST_DIFF=$'composer.lock' "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "lockfile-only diff returns SKIP"

# Test 4: PHP code change → RUN (exit 1)
set +e
MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' "$SCRIPT" >/tmp/should-skip-out 2>&1
exit_code=$?
set -e
out=$(cat /tmp/should-skip-out)
rm -f /tmp/should-skip-out
assert_eq "${out%% *}" "RUN" "PHP code change returns RUN"
assert_eq "$exit_code" "1" "PHP code change exits 1 (RUN convention)"

# Test 5: env var skip → SKIP
out=$(MANUAL_TESTING_SKIP=1 MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "env var override forces SKIP"

# Test 6: tests-only change → SKIP
out=$(MANUAL_TESTING_TEST_DIFF=$'tests/Feature/CompanyTest.php' "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "tests-only change returns SKIP"

# Test 7: branch=main → SKIP regardless of diff
out=$(MANUAL_TESTING_TEST_BRANCH=main \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "branch=main forces SKIP"

# Test 8: branch=master → SKIP
out=$(MANUAL_TESTING_TEST_BRANCH=master \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "branch=master forces SKIP"

# Test 9: branch=release/2026 → SKIP
out=$(MANUAL_TESTING_TEST_BRANCH=release/2026 \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "SKIP" "branch=release/* forces SKIP"

# Test 10: branch=feat/foo → does NOT force SKIP, regular diff path applies
out=$(MANUAL_TESTING_TEST_BRANCH=feat/foo \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "RUN" "branch=feat/foo does not block RUN"

# Test 11: subdirectory containing "tests" in name does NOT match tests/* pattern
out=$(MANUAL_TESTING_TEST_DIFF=$'app/tests-helpers/foo.php' \
      "$SCRIPT" 2>&1 || true)
assert_eq "${out%% *}" "RUN" "non-prefix tests/* path returns RUN"

echo "ALL TESTS PASS"
