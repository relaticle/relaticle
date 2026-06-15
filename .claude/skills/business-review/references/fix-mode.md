# Stage 6 — Fix mode (find → fix → cold re-verify → re-gate)

The loop the user actually runs ("go ahead fix all issues, reverify, post review") made
first-class. Reviewer independence is preserved by **re-verification rigor**, not by
refusing to touch code.

## When it runs

- `--fix` was passed, OR the user asks during/after the run ("fix all issues", "fix
  inside this PR", choosing option [2] at the end-of-run prompt).
- NEVER silently. A review without a fix request stays report-only.

## The loop (per finding, ordered by severity)

1. **Work order** = the finding's "Findings to act on" entry (file:line, repro,
   suggested action). Fix at the right layer; respect project rules (actions classes,
   tenant scoping, pint/phpstan gates before claiming done).
2. **Cold re-verify against the ORIGINAL repro, verbatim** — fresh browser session,
   the finding's recorded `repro[]` steps followed literally (the bug that "stayed
   fixed" for three weeks until a customer re-reported it was never re-walked from its
   original repro — this step exists because of that). If the diff was authored in this
   session, the re-verification runs in a **fresh verifier subagent**; on capacity
   failure, say so and downgrade the claim to "fixed, not independently re-verified."
3. Artifact: `_fix-verify/<finding-id>.png` (or deterministic evidence) per fix.
4. **Re-run the gates** over the affected journeys (not the whole fleet): the touched
   journey's value verdict is re-judged, `aggregate_verdicts.py` re-run, REVIEW.md
   updated in place with a `## Fixes applied` section (finding → commit/diff → re-verify
   artifact → status).
5. **Ledger update** (`regression-ledger.md`): every confirmed-then-fixed finding whose
   class could recur becomes a `regressions.json` entry (id, class, trigger, check,
   repro). This is mandatory — findings must compound.

## Hard rules

- The original repro is the acceptance test for the fix — "should work now" claims are
  forbidden; show the re-walked repro passing.
- A fix that changes the diff under review invalidates the prior verdict — the updated
  REVIEW.md states the new head SHA and which journeys were re-judged.
- Fix-mode never edits this skill's gates/scripts to make a run pass, and never weakens
  a ledger entry to dodge a sweep.
- Pre-commit quality checks (pint, rector dry-run, phpstan, type coverage, targeted
  tests) run before any "fixed" claim, per project CLAUDE.md.
- Publishing after fix mode re-checks the PR head SHA and re-runs report gates 6b/6c.
