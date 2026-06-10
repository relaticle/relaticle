#!/usr/bin/env bash
# Stop hook for the manual-testing skill.
# Emits a system-reminder to stdout when:
#   1. The diff is testable (per should-skip.sh)
#   2. The skill has not yet run for the current turn (per .context/testing/last-run.json)
# Otherwise silent.
#
# Test injection:
#   MANUAL_TESTING_TEST_DIFF — newline-separated paths (forwarded to should-skip.sh)
#   MANUAL_TESTING_TEST_BRANCH — fake branch name (forwarded to should-skip.sh)
#   CLAUDE_TURN_ID — current turn id; used to compare against state file

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
SHOULD_SKIP="$REPO_ROOT/.github/skills/manual-testing/bin/should-skip.sh"
STATE_FILE="$REPO_ROOT/.context/testing/last-run.json"

# If the skip script is missing, do nothing (skill not installed yet)
[[ -x "$SHOULD_SKIP" ]] || exit 0

# Check whether the diff is testable.
# should-skip exits 0 = SKIP (untestable), 1 = RUN (testable).
if "$SHOULD_SKIP" >/dev/null 2>&1; then
    # exit 0 from should-skip means SKIP, so the hook is silent
    exit 0
fi

# Diff is testable. Check whether the skill already ran this turn.
turn_id="${CLAUDE_TURN_ID:-${CLAUDE_SESSION_ID:-unknown}}"
if [[ -f "$STATE_FILE" ]]; then
    last_turn=$(grep -o '"turn_id":"[^"]*"' "$STATE_FILE" 2>/dev/null | sed 's/.*:"//;s/"$//' || echo "")
    if [[ "$last_turn" == "$turn_id" ]]; then
        # Skill already ran this turn — stay silent
        exit 0
    fi
fi

# Emit a system-reminder
cat <<'EOF'
<system-reminder>
The manual-testing skill has not run for this turn but the current diff includes testable code changes. Invoke the manual-testing skill before reporting work as complete. To skip intentionally, include [skip-qa] in your response or set MANUAL_TESTING_SKIP=1.
</system-reminder>
EOF
exit 0
