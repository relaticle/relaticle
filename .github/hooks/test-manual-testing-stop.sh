#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOK="$SCRIPT_DIR/manual-testing-stop.sh"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
STATE_DIR="$REPO_ROOT/.context/testing"
STATE_FILE="$STATE_DIR/last-run.json"

mkdir -p "$STATE_DIR"

assert_contains() {
    if [[ "$1" != *"$2"* ]]; then
        echo "FAIL: output '$1' did not contain '$2'"
        exit 1
    fi
    echo "OK: $3"
}

assert_empty() {
    if [[ -n "$1" ]]; then
        echo "FAIL: expected empty output, got '$1'"
        exit 1
    fi
    echo "OK: $2"
}

# Setup: clear state
rm -f "$STATE_FILE"

# Test 1: testable diff + skill not yet run → emit reminder
out=$(MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      MANUAL_TESTING_TEST_BRANCH=feat/test \
      "$HOOK" 2>&1)
assert_contains "$out" "manual-testing skill" "emits reminder when skill not run"
assert_contains "$out" "system-reminder" "wraps output in system-reminder"

# Test 2: skill already ran this turn → silent
echo '{"turn_id":"abc123","ran_at":"2026-05-05T14:00:00Z"}' > "$STATE_FILE"
out=$(CLAUDE_TURN_ID=abc123 \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      MANUAL_TESTING_TEST_BRANCH=feat/test \
      "$HOOK" 2>&1)
assert_empty "$out" "silent when skill already ran this turn"

# Test 3: testable diff + state file from old turn → emits reminder
out=$(CLAUDE_TURN_ID=newturn999 \
      MANUAL_TESTING_TEST_DIFF=$'app/Models/Company.php' \
      MANUAL_TESTING_TEST_BRANCH=feat/test \
      "$HOOK" 2>&1)
assert_contains "$out" "manual-testing skill" "emits reminder when state is stale"

# Test 4: trivial diff → silent (no reminder needed)
rm -f "$STATE_FILE"
out=$(MANUAL_TESTING_TEST_DIFF=$'README.md' \
      MANUAL_TESTING_TEST_BRANCH=feat/test \
      "$HOOK" 2>&1)
assert_empty "$out" "silent when diff is trivial"

# Cleanup
rm -f "$STATE_FILE"

echo "ALL TESTS PASS"
