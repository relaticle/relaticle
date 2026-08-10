# Regression ledger — findings must compound

`regressions.json` (skill root) converts every confirmed finding into a **standing
check**. The proof of need: review 209 found a missing enum match-arm and even wrote
"audit other match-on-enum sites"; two weeks later an ai-approved review let the same
defect class ship in PR 326 and production crashed (Sentry 127436080, hotfix v3.3.7).
Reports get read once; the ledger gets ENFORCED forever.

## Entry shape

```json
{
  "id": "REG-001",
  "class": "enum-case-added-without-consumer-update",
  "found": "<dates + where, incl. recurrences>",
  "severity": "critical|high|medium",
  "trigger": {
    "paths": ["app/Enums/", "packages/*/src/Enums/"],
    "added_line_pattern": "^\\+\\s*case\\s+\\w+",
    "change_types": ["form", "mutation"]
  },
  "check": "<what the reviewer must do when the trigger matches>",
  "repro": ["<verbatim steps — the replay script for --reverify>"]
}
```

Trigger semantics (implemented in `scripts/check_regressions.py`):
- `paths` — glob-ish prefixes matched against touched files (`*` wildcard supported).
- `added_line_pattern` — regex matched against ADDED lines in the patch (optional).
- `change_types` — matched against `diff-classification.json.change_types` (optional).
- An entry matches when **(paths OR change_types)** matches **AND** the
  `added_line_pattern` (when present) matches. No matches → entry dormant.

## Lifecycle

1. **Stage 1**: `check_regressions.py $REVIEW_DIR` → `regression-checks.json` (matched
   entries).
2. **Stage 2 gate**: the plan must schedule every matched entry — frontmatter
   `regression_checks: [{id, journey, status: planned|not-applicable, reason?}]`;
   `check_regressions.py $REVIEW_DIR --plan` exits non-zero on any unscheduled match.
   `not-applicable` requires a written reason (audited in the report).
3. **Stage 3**: the covering journey walks the entry's `check`; result + artifact
   recorded.
4. **Stage 5**: REVIEW.md `## Regression sweep` table; an unwalked, un-waived match caps
   the label at `ai-needs-human`.
5. **Stage 6 (fix mode)**: every confirmed-then-fixed finding with a recurrence-capable
   class APPENDS an entry. Entries are never deleted to make a run pass; retire one only
   when the class is structurally impossible (e.g. the code pattern no longer exists),
   with a dated note.
6. **`--reverify REG-NNN`**: replay that entry's `repro[]` verbatim in a fresh session,
   report pass/fail + artifact. This is the answer to "is <old bug> still fixed?".

## Authoring guidance

- Ledger entries are for **earned, recurrence-capable classes** (enum consumers,
  double-submit, tenant scoping, import validation, composer survival) — not every
  one-off finding. Keep the ledger small enough that every match is taken seriously.
- Write `repro[]` so a stranger (or a fresh subagent) can follow it literally.
- Use the project's own vocabulary in `trigger.paths` — paths outlive prose.
