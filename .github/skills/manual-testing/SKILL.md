---
name: manual-testing
description: "Use immediately after finishing any code change in Relaticle — implementation, fix, refactor, migration — before reporting work as complete. The skill itself decides whether to run; do not skip on 'small' changes. Triggers an adversarial post-task QA pass: reads the diff, scores risk, generates an SBTM charter, drives a real browser through a pairwise-reduced test matrix across Filament/Livewire/REST/MCP surfaces, classifies findings by severity, auto-fixes High findings within strict guardrails, and promotes High+ findings to permanent Pest browser regression tests. Reports land in .context/testing/reports/. Skip with [skip-qa] in the user message or MANUAL_TESTING_SKIP=1."
license: MIT
metadata:
  author: relaticle
---

# Manual Testing — Relaticle

Adversarial post-task QA. Pretend 1000s of users in production are about to exercise this change from every angle. Find what they would find. Fix what's safe to fix. Report what isn't.

## When to invoke

Self-invoke immediately after finishing any code change in this codebase, before reporting work as complete. The skill decides for itself whether the change is testable; do not pre-filter.

## Skip conditions

Run `.github/skills/manual-testing/bin/should-skip.sh` first. If it exits 0, exit the skill with one line: the reason from the script. If it exits 1, proceed.

Skip rules (encoded in the script):

1. `MANUAL_TESTING_SKIP=1` env var — hard override.
2. Branch is `main`, `master`, or `release/*`.
3. Diff is empty.
4. Every path in the diff matches a trivial pattern: `*.md`, `*.txt`, `*.lock`, `*.gitignore`, `composer.lock`, `package-lock.json`, `yarn.lock`, `tests/**`.

Additionally, **the skill itself** (not the script) must check the user's message in the current turn for `[skip-qa]`, `skip qa`, `no test`, or `dont test` BEFORE invoking the script. If found, exit with `SKIP user-request:<phrase>` logged to `.context/testing/reports/skipped.log`. The script handles env-var, branch, empty-diff, and trivial-path cases; the user-message gate is the skill's responsibility because the script has no access to chat context.

## Workflow phases

### 1. DETECT

Run `.github/skills/manual-testing/bin/should-skip.sh`. If exit 0, exit the skill with the one-line reason. If exit 1, capture the diff: `git diff HEAD --name-only` for paths, `git diff HEAD --stat` for the size summary.

### 2. CLASSIFY

Load `references/risk-rubric.md`. Score Likelihood and Impact against the diff:

1. **Likelihood**: pick the anchor (1-5) that best matches the most-impactful change in the diff. When in doubt, round up.
2. **Impact**: start with the rubric anchor. Then grep the diff for the high-impact tag patterns. Any match forces Impact = 5 — record which pattern fired.
3. **Total**: `L × I`, classify into smoke/light/medium/deep tier.
4. **Surfaces**: walk the surface scoping table; any matching path adds its surfaces to the matrix.

Print the classification at the top of the working notes:

```
Risk: 4×5=20 → deep
- Likelihood 4: cross-cutting refactor of TaskPolicy + 2 callers in Filament and MCP.
- Impact 5: high-impact tag matched (`app/Policies/TaskPolicy.php`).
- Surfaces: Filament UI, REST API, MCP.
- Auto-fix ceiling: High only, never in auth/tenancy/migration code.
```

### 3. CHARTER

Load `references/personas.md`, `references/oracles.md`, `references/tours.md`. Compose the charter using exactly this grammar:

> **Explore** `<changed area>` **with** `<personas, tours, surfaces, time budget>` **to discover** `<information goal>`.
> **Definition of Done:** machine-checkable list of assertions.

Definition of Done items must be **measurable**. Bad: "form looks good." Good: "Filament Tasks list view loads in <2s for 1000 records."

Use `templates/charter.md` as the structural starting point and fill in all `<placeholders>`.

**Time budgets per tier:** smoke 2 min, light 5 min, medium 10 min, deep up to 60 min.

If the charter has fewer than 3 DoD items, regenerate — DoD is what prevents the LLM from declaring success on incomplete work.

### 4. PLAN

Build the candidate matrix (Persona × Surface × Tour × Data-state). Reduce in this order:

1. Filter by surface scoping (from CLASSIFY phase).
2. Filter by tour-relevance per surface (per `references/tours.md` selection table).
3. Apply pairwise: every (Persona, Surface) pair appears at least once; every (Surface, Tour) pair at least once; every (Persona, Data-state) at least once.
4. **Hard-add** the multi-tenant checklist row whenever any model/policy/scope path was touched (see `references/multi-tenant-checklist.md`). Never reduced away.
5. Boundary-value analysis: for each text input on the diff, add 2-4 nasty-data cells from `references/data-nasties.md`.

Target matrix sizes:

| Tier | Cells |
|---|---|
| smoke | 3-5 |
| light | 8-12 |
| medium | 15-25 |
| deep | 30-50 + multi-tenant checklist |

**On smoke-tier cell count:** the rubric's "1 persona, single surface, happy path + 1 negative" is the *baseline*. The 3-5 target comes from boundary-state expansion (typical / empty / error) within that single persona+surface combination. So a smoke run is genuinely lightweight — it just rotates one cell through 3-5 data states.

Print the matrix as a table at the top of the report (one row per cell). Each cell will be filled with an outcome during EXECUTE.

### 5. SETUP

Verify the dev environment is reachable:

```bash
agent-browser open https://calm-lemur.test
agent-browser get title
```

If unreachable, exit the skill with `BLOCKED: dev server unreachable at https://calm-lemur.test`. Suggest: check Herd status, run `herd start`, verify `.env` `APP_URL`.

If two teams aren't present (required by the multi-tenant checklist when applicable), seed a second team — see `references/multi-tenant-checklist.md` setup section.

Gather login-link URLs for every persona in the matrix. Cache them in `.context/testing/state/login-urls.json`.

### 6. EXECUTE

For each cell in the matrix (in order — multi-tenant checklist runs **first** when present, so a leak halts execution before further cells run):

1. Load `references/surfaces.md` for the relevant surface playbook.
2. Log in as the persona via login-link.
3. Drive the surface through the tour's steps.
4. Apply the data-nasty payload (if the cell is a boundary-value cell).
5. **Visual sweep (Filament UI / Livewire surfaces only):** if the cell's tour is Supermodel — or if any browser-touching cell hasn't yet been visually swept — run the probes from `references/visual-probes.md` (P1–P8) at the viewports listed there, plus the state-explosion checklist. Take an annotated screenshot per state and per viewport. Then answer the Image-oracle rubric in `references/oracles.md` against the captured screenshots.
6. Observe.
7. Cite an oracle (`references/oracles.md`) if anything went wrong. If no oracle applies, log a "neutral note" and move on.
8. Capture: screenshot, console output, page errors, network 4xx/5xx, DB state if mutation was attempted.

Update the matrix table in the report with the cell outcome (OK / Finding F-N / Skipped <reason>).

If a Critical finding surfaces (multi-tenant leak, auth bypass, data loss, executed XSS/SQLi), **halt execution**. Do not run remaining cells. Skip directly to TRIAGE → REPORT.

### 7. TRIAGE

For each finding produced in EXECUTE, run RIMGEA in this order. If a step fails, the finding is downgraded:

1. **R**eplicate — verify the bug reproduces 3/3 in fresh sessions. If <3/3 → demote to "flaky observation" (logged but not reported).
2. **I**solate — reduce to the smallest reproducible action sequence.
3. **M**aximize — actively try to make the impact worse (more data, more users, longer-running). Discovered escalations re-classify severity.
4. **G**eneralize — re-run the probe against sibling resources (Companies/People/Opportunities/Tasks/Notes). Each affected sibling gets its own finding entry, marked as related.
5. **E**xternalize — write the impact in real-user language ("A salesperson rushing through a customer call would..."). Forces UX-honest framing.
6. **A**rticulate — single paragraph + numbered repro steps + screenshot path + console snippet.

Then classify severity (hardcoded — no LLM judgment):

| Severity | Examples |
|---|---|
| **Critical** | Data loss · auth bypass · multi-tenant leak · executed XSS/SQLi · IDOR · `migrate:fresh` fails · build/deploy break |
| **High** | Happy path broken · console error breaking UX · 500 during normal use · validation drift across surfaces · new N+1 |
| **Medium** | Validation message wrong/missing · loading state stuck on error · inconsistent UI · slow but not broken |
| **Low** | Typos · spacing · icon size · missing tooltip · cosmetic only |

The Critical/High line: if a fix in this code area could itself introduce a security regression, it's Critical. Auth, tenancy, migrations, encryption — never auto-fix.

### 8. ACT

Per finding, by severity:

**Critical:** Halt. Write the finding to the report with full RIMGEA detail. Do **not** modify code. Skill exits with: `BLOCKED: <N> critical finding(s). See <report-path>.`

**High:** Enter the auto-fix loop, **only if** the auto-fix permission ceiling allows it (per `references/risk-rubric.md`):

1. Form a single-sentence hypothesis about the cause based on RIMGEA-Isolate's smallest repro.
2. Apply the smallest fix that addresses the hypothesis. **Hard cap: ≤30 changed lines per attempt.** Larger fixes hand off to the user.
3. Re-run only the failing cell. Verify it passes.
4. Re-run the **full charter**. Check the fix didn't introduce regressions.
5. Loop ≤3 attempts. If still broken, downgrade to Medium with note "auto-fix exhausted, hand-off to user."

**Auto-fix guardrails (hard rules):**

- Never modifies: `app/Policies/**`, `app/Models/Concerns/BelongsToTeam*`, `database/migrations/**`, anything under `Auth\`/`Sanctum\`/`Fortify\`, `relaticle/custom-fields` package code.
- Per-attempt diff cap: 30 changed lines.
- Risk-tier permission ceiling overrides severity policy. At smoke tier, even High findings become "report only."

Every attempt's hypothesis, diff, and outcome is logged in the report's "Auto-fix attempts" section. Nothing is silently patched.

**Medium / Low:** List in report. No auto-fix. User triages.

### 9. PROMOTE

When a High finding is fixed and the matrix is clean (re-run produced no regressions), write a Pest browser test capturing the bug's repro.

**Test placement (decide at runtime — match existing convention):**

1. Search recursively for related test files: `find tests -iname '*<resource>*' -type f` and `find tests -path '*<feature-keyword>*' -type f`. Real Relaticle layout puts Filament tests under `tests/Feature/Filament/...` and `tests/Browser/CRM/...` (subdirectories), not flat.
2. Default to a **Pest Livewire feature test** for Filament/Livewire validation/action bugs (uses `livewire()` helper). Use a **Pest browser test** only when the bug genuinely requires a real browser (JS, CSS, multi-page).
3. Examples (verify at runtime — paths are illustrative):
   - Bug in a Filament Resource form-field validation → likely target: `tests/Feature/Filament/App/Resources/<Name>ResourceTest.php`
   - Bug in MCP tool → likely target: `tests/Feature/Mcp/<Name>Test.php`
   - Bug requiring real browser → likely target: `tests/Browser/CRM/<Name>BrowserTest.php`

**Stub to copy from `templates/regression-test.php.stub` and fill in:**

```php
<?php

declare(strict_types=1);

it('regresses <bug-slug>: <one-line summary>', function () {
    // Externalize: <real-user impact paragraph>

    // Arrange: <smallest seed needed for repro>

    $this->browse(function ($browser) {
        // <smallest-repro steps from RIMGEA Isolate>

        // <oracle from FEW HICCUPPS that detected the original bug>
        $browser->assertSee('<expected good state>');
    });
});
```

**Promotion gates** (all must pass):

1. RIMGEA-Generalize was run. If sibling resources had the same bug, **one test per affected resource**.
2. De-duplication: `grep -r "regresses <bug-slug>"` against `tests/`. If found, skip — already locked in.
3. Test runs and **passes**: `php artisan test --compact --filter="regresses <bug-slug>"`. A test that doesn't actually run is worse than no test.

**Tests are staged but not committed.** They show up in `git status` for the user to review and commit alongside the fix. Respects the "don't commit unless asked" rule.

### 10. REPORT

Load `templates/report.md`. Fill in the run metadata, classification, charter, matrix table, findings (one block per finding), auto-fix attempts, regression tests written, and PROOF debrief.

Save to `.context/testing/reports/YYYY-MM-DD-HHMM-<task-slug>.md` (slug from the user's task description, kebab-cased).

Update `.context/testing/state.json` with:

```json
{
  "last_run": "<ISO 8601 timestamp>",
  "last_head": "<git rev-parse HEAD>",
  "running_total": {"critical": <N>, "high": <N>, "medium": <N>, "low": <N>},
  "regression_tests_written": <N>,
  "auto_fix_success_rate": <fraction 0-1>
}
```

(Increment running totals — read prior state, add this run's counts. If `.context/testing/state.json` does not exist on first run, initialize all running totals to 0 before adding this run's counts.)

Update `.context/testing/last-run.json` with the current turn id (for the Stop hook to skip):

```json
{"turn_id": "<CLAUDE_TURN_ID or session id>", "ran_at": "<ISO timestamp>"}
```

In Claude's chat reply: a **tight summary** — severity counts, fixed/deferred breakdown, regression tests written, link to the full report. No more.

Example chat-reply summary:

> Manual-testing run complete (deep tier, 14 min, 22/25 cells). 1 Critical (multi-tenant leak in TaskPolicy — BLOCKED, awaiting your triage), 2 High (both auto-fixed, 1 regression test written), 4 Medium, 1 Low. Full report: `.context/testing/reports/2026-05-05-1432-task-policy-refactor.md`.

## References

The skill loads references conditionally based on the current charter. The full set:

- `references/risk-rubric.md` — Likelihood × Impact scoring + high-impact tag list + score → action gates + surface scoping
- `references/personas.md` — 8 personas (Default + 7 archetypes) and tier → persona-set mapping
- `references/oracles.md` — FEW HICCUPPS oracle catalogue + Image-oracle 12-item rubric
- `references/tours.md` — 8 Whittaker-style exploration tours and surface → tour mapping; Supermodel is mandatory on browser surfaces and uncapped by tier
- `references/surfaces.md` — Filament UI / Livewire / REST API / MCP playbooks
- `references/visual-probes.md` — 8 deterministic `agent-browser eval` probes (overflow, clipping, tap-target, stuck-loading, image hygiene, focus, contrast, layout shift) + viewport sweep + state-explosion checklist (loaded in EXECUTE on browser surfaces)
- `references/multi-tenant-checklist.md` — Cross-Tenant Spy 5-check protocol (non-negotiable when policy/model/scope path touched)
- `references/data-nasties.md` — boundary/garbage payload library, including 22-custom-field-type sub-sections

Templates:

- `templates/charter.md` — SBTM charter template (loaded at CHARTER phase)
- `templates/report.md` — PROOF debrief + RIMGEA finding template (loaded at REPORT phase)
- `templates/regression-test.php.stub` — Pest 4 regression test stub with Livewire/Browser dual-template (loaded at PROMOTE phase)

Helper script:

- `bin/should-skip.sh` — diff-testability gate (called at DETECT phase and by the Stop hook)
