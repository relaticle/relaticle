Task: Business-review local work or a Relaticle pull request end-to-end in a real browser (v3 — panel-of-QAs engine with environment discovery, regression ledger, adversarial verification, and fix mode).

## Input

The user provides EITHER flags or natural language — parse both:

- Nothing → **local mode**: current branch vs `main`, committed changes only
- `--working-tree` → local mode including uncommitted changes
- A PR number (`209`, `#209`, or a PR URL) → **PR mode** (`--pr <N>`)
- `--describe "<text>"` → AC from the text, no diff
- `--publish` → post to the PR after gates pass (PR mode)
- `--fix` → enter fix mode after the verdict (fix → cold re-verify each finding against its original repro → re-gate)
- `--no-prompt` → suppress the end-of-run prompt
- `--reverify REG-NNN` → replay one regression-ledger entry verbatim and report

Natural-language mapping (apply even without flags):
- "deploy to prod for 100,000 customers", "stress testing", "every single angle/detail", "end-2-end" → Tier 3 override
- "post review into PR (with screenshots)" → `--publish` (screenshots are always inline)
- "fix all issues", "fix and reverify" → `--fix`
- "quick check", "smoke" → Tier cap 1 (never below a critical signal)

## What to do

Invoke the `business-review-task` skill via the `Skill` tool with the parsed, normalized args. The skill is the single source of truth for the workflow — do not paraphrase or shortcut its stages (environment discovery → preflight → understand → tier+plan → fleet → verify → report → optional fix mode → optional publish).

For an explicit A/B comparison against the frozen gen-1 baseline, the user must ask for `business-review-v1` by name.

## Scope

Browser verification of intended behavior — NOT code review, security review, or scope-creep analysis (`/code-review`, `/review`, `/deep-review`, `/pr-fix-workflow`).

## Local backing store

Artifacts land in `.context/reviews/<pr-number|local>/` (gitignored): project-profile.json (derived env), plan.md, journey-map.json, regression-checks.json, persona findings, verifier confirmations, screenshots, verdict-final.json, REVIEW.md. `.context/reviews/local/LATEST.txt` points at the latest local run. Leave everything in place after the run.

## Rules (mirror of the skill's hard rules)

- Never `migrate:fresh`/`migrate:refresh`. Never tinker/DB-write to fix or fake a result.
- Test records use the `br-rel-<run>-…` prefix; leave test data in place.
- `blocked` (degraded channel / failed Tier-3 env gate) stops the run — no verdict, no publish, no label.
- Publish only on explicit `--publish` or user confirmation at the end-of-run prompt; never from a blocked run.
- Stay on the review branch when finished; print the prior branch.
