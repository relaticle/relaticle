# Stage 2 — Run reference

Covers the three-lens planning pass, plan schema, iteration protocol, health gate, and evidence types.

---

## Three-lens planning

After Stage 1 (Understand) has produced `requirements.md`, write the plan file. The plan is the deterministic input to execution — get this right and execution becomes mechanical.

### Step-by-step

1. **Classify the diff**

   ```bash
   python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/classify_diff.py \
     "$REVIEW_DIR/pr-diff.patch" > "$REVIEW_DIR/diff-classification.json"
   ```

   The `change_types` array tells you which element types the diff touched. Relaticle-specific types: `custom_fields`, `tenant`, `import_wizard`, `ai_chat`, `sysadmin`, `api`.

2. **Consult reference material**

   - Open `checks-matrix.md` — for each element type in `change_types`, scan the per-element checks table and the suggested scenarios column.
   - Read `requirements.md` — confirm the AC list and intent.
   - Read `$REVIEW_DIR/code-context.json` — module summaries, what tests claim, history signals, blind spots. This is the digested output of Stage 1's code-context-analyzer subagent and the primary source for *what the touched code actually does*.
   - **Lazy escalation:** if a case needs detail beyond what the summary gives (e.g. exact method signature, full body of a related test), Read the specific file. Record the path in the case's `setup_context_reads: []` — this is the audit trail showing what additional reads were necessary. Do NOT pre-emptively bulk-read; the subagent already did that work in an isolated context.

3. **Plan cases through three lenses**

   These are thinking aids, not output structure:

   | Lens | Question to ask | Source |
   |---|---|---|
   | **Functional** | Does each AC work as the description claims? | `requirements.md` |
   | **Adversarial** | What breaks under stress? Modal close paths, validation under edge inputs, double-submit, very long input, special chars, emoji, error states, custom-field weirdness. | checks-matrix per-element checks |
   | **Coverage gaps** | Mobile viewport if blade touched? Console-clean check? Multi-tenant scope (would another team see this?)? Pennant-gated states? Sysadmin panel implications? | diff classification |

4. **Produce a flat case list** — `## case-1`, `## case-2`, etc. Each case targets one focused outcome. No round headers.

5. **Judgment guidance**

   - Narrow diff (one bug fix, one AC) → 2–3 cases may suffice. Don't pad.
   - Wide diff touching multiple CRM surfaces (records + custom fields + import) → 6–10 cases reasonable.
   - More than ~12 → prioritize ruthlessly. Drop low-value cases.
   - Reference material exists to make sure checks are *considered*. They don't all have to be tested — just don't accidentally skip them.

6. **Validate**

   ```bash
   python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/validate_plan.py "$REVIEW_DIR/plan.md"
   ```

   Fix any structural errors reported. The validator checks schema sanity — it does NOT enforce lens labels or case counts.

### Parallel planning subagents (optional)

For wide diffs (≥ 200 lines of diff with new test files added), the orchestrator may dispatch `diff-analyzer` and `intent-analyzer` subagents in parallel via the `Agent` tool to read the diff more thoroughly. See `agents/diff-analyzer.md` and `agents/intent-analyzer.md`. The output of those subagents feeds `requirements.md` before planning continues.

Both are pure-read. They're the only allowed parallel dispatch in Stages 2 and 3 — and only at planning start.

For narrow diffs (< 200 lines), skip subagents — read the diff yourself, it's cheaper.

### Anti-patterns

- ❌ **One case per checks-matrix entry.** The matrix has hundreds of checks. You'd never finish. Pick the highest-risk ones per scope.
- ❌ **Naming cases after lenses.** "Functional case 1, Adversarial case 1". Just name them by what they test.
- ❌ **Padding to hit a case count target.** If 3 cases cover the scope, plan 3.
- ❌ **Skipping `evidence_type`.** Every step needs one declared. If unsure, use `screenshot_judgment` honestly and note it in confidence scoring.
- ❌ **Inventing ACs.** If the description has no clear AC, surface this via the autonomy contract — don't fabricate.

---

## Plan schema

The plan file at `$REVIEW_DIR/plan.md` has a JSON frontmatter block (HTML comment) containing all structured data, followed by an optional Markdown body for human-readable prose.

### Frontmatter (the source of truth)

```html
<!--json
{
  "pr_number": 87,
  "sha": "abc123def0",
  "generated_at": "2026-05-27T14:00:00Z",
  "change_types": ["modal", "form", "custom_fields"],
  "total_cases": 2,
  "cases": [
    {
      "id": "1.1",
      "name": "Create company with new industry field",
      "acs": [1],
      "mode": "browser",
      "setup": ["login as manuk.minasyan1@gmail.com", "navigate to /app/<team>/companies/create"],
      "verification_steps": [
        {
          "id": "step-1.1.1",
          "action": "fill name 'BR Test Co', pick industry 'SaaS'",
          "expected": "submit button enabled",
          "evidence_type": "a11y_ref"
        },
        {
          "id": "step-1.1.2",
          "action": "click submit",
          "expected": "success toast 'Company created', record persists in DB",
          "evidence_type": "deterministic"
        }
      ],
      "screenshot": {
        "selector": ".fi-section",
        "callout_target": ".fi-section-header",
        "callout_label": "Industry: SaaS persisted",
        "evidence": "Industry: SaaS"
      }
    }
  ]
}
-->

# Plan body (optional, prose-only)
```

### Frontmatter fields

| Field | Required | Source | Notes |
|---|---|---|---|
| `pr_number` | yes | input arg | integer; use 0 in pure local mode |
| `sha` | yes | `gh pr view ... headRefOid` (PR) or `git rev-parse HEAD` (local) | first 10 chars |
| `generated_at` | yes | ISO 8601 timestamp | |
| `change_types` | yes | `classify_diff.py` output | array of strings |
| `total_cases` | yes | computed | integer (length of `cases`) |
| `cases` | yes | computed | array of case objects |

### Case object fields

| Field | Required | Notes |
|---|---|---|
| `id` | yes | dotted identifier like `1.1`, `2.3` — unique within the plan |
| `name` | yes | human-readable case name |
| `acs` | yes | array of integer AC IDs covered (e.g. `[1, 2]`), matching the `id` fields in `acceptance-criteria.json`. Use `["implicit"]` for checks-matrix scenarios not tied to an AC. |
| `mode` | yes | `browser` or `pest-only` |
| `change_types` | no | subset of frontmatter's `change_types` this case targets |
| `viewport` | no | e.g. `"375x667"` for mobile cases; default `1920x1080` |
| `setup` | yes | array of human-readable setup steps |
| `setup_context_reads` | no | array of file paths the agent Read during planning beyond what `code-context.json` already summarized — audit trail for lazy escalation (see `references/understand.md` Step 1a) |
| `verification_steps` | yes | array of step objects — must be non-empty |
| `screenshot` | yes when `mode: browser` | screenshot spec for the case's main evidence shot |

### Verification step fields

| Field | Required | Notes |
|---|---|---|
| `id` | yes | format `step-<case-id>.<n>` |
| `action` | yes | what to do in the browser |
| `expected` | yes | what to assert |
| `evidence_type` | yes | one of `deterministic`, `a11y_ref`, `snapshot_diff`, `screenshot_judgment` |

### Screenshot object fields

| Field | Required | Notes |
|---|---|---|
| `selector` | yes | CSS selector for the area to capture |
| `callout_target` | yes | CSS selector to annotate with the callout |
| `callout_label` | yes | short label rendered next to the callout arrow |
| `evidence` | yes | literal text snippet the screenshot proves is present |

If a browser case explicitly has no screenshot (pure DB-state check, or proven by an earlier case):

```json
"screenshot": {"none": "covered by case 1.1 — same modal"}
```

The `none` value must be a non-empty string explaining why. Setting `none` AND any of the four populated fields together is rejected by the validator.

### What `validate_plan.py` enforces

- Frontmatter JSON parses cleanly
- Required top-level fields present
- `cases` is a list (may be empty for infra-only diffs)
- Every case has required fields
- `mode` is `browser` or `pest-only`
- Browser cases have populated `screenshot` (four fields or `{none: <reason>}`)
- Every verification step has `id`, `action`, `expected`, `evidence_type`
- `evidence_type` is one of the four allowed values
- Case `id`s are unique within the plan
- AC IDs referenced in cases exist in `acceptance-criteria.json`

### What `validate_plan.py` does NOT enforce

- Case count caps — judgment-driven; convention ≤ ~12, warning only
- Lens labels — no `round:` or `lens:` field
- Coverage of every `change_type`
- checks-matrix completeness
- The Markdown prose body below the frontmatter

---

## Iteration protocol

A case can run 1, 2, or 3 iterations. **You (the agent) decide when to stop** based on the nature of the failure. Each iteration's artifacts go to `case<N>/iter-<N>/`. Cap is 3 iterations, period.

### Iter 1 — As planned (always)

1. Apply case setup (login, navigate, seed data).
2. For each verification_step in plan order:
   - Perform the declared action.
   - Run health-gate JS at navigation points where it adds signal.
   - Assert the expected outcome.
   - Emit `STEP_PASS|<step.id>|<evidence_type>|<artifact_path>` OR
     `STEP_FAIL|<step.id>|<expected>→<actual>|<screenshot_path>`.
3. If all STEP_PASS → case complete, score holistically (guidance: up to 95 confidence).
4. If any STEP_FAIL → decide whether iter 2 is worth running.

### Iter 2 — Diagnose + adjust (when warranted)

Use when the iter-1 failure looks like an agent approach issue, not a real bug.

**Skip iter 2** when:
- Server returned 500 / 422 / 404 unexpectedly — likely a real bug.
- Expected element doesn't exist anywhere in the page source — feature is missing.
- Behavior clearly contradicts the AC.

For each failing step:

1. **Diagnose:** read console history, inspect DOM around selector (0/1/many?), check element visibility + computed styles + ARIA state, check Livewire state via `$wire`. Write 2–3 sentence diagnosis to `case<N>/iter-2/diagnosis-<step.id>.md`.

2. **Adjust:** if selector matched 0 → try a11y label / sibling traversal / different class. If element existed but action didn't take effect → longer wait, different event (mouseup vs click), dispatch Livewire event directly. If response was correct but slow → extend timeout, retry.

3. **Re-run** the failing step (only the failing step, not the whole case).

4. Emit STEP_PASS / STEP_FAIL again.

5. If all STEP_PASS → score around 75–85.

6. If still failing → decide whether iter 3 is worth it.

### Iter 3 — Max instrumentation (when warranted)

Use when iter-2 still failed AND you want a full diagnostic bundle before concluding "real bug" — typically when the failure could go either way and a human reviewer will want all the evidence.

1. **Enable everything:** HAR, console history with stack traces, video recording, DOM snapshot before AND after the failing step.

2. **Re-run** the failing step with verbose logging.

3. If STEP_PASS → score around 60–75, flag `flaky=true` for human triage.

4. If STEP_FAIL → case verdict = fail. Save full bundle to `case<N>/iter-3/diagnostics/`:
   - `network.har`, `console.log`, `video.mp4`, `dom-before.html`, `dom-after.html`, annotated failure screenshot.

### Agent freedom

The point of 1–3 iterations is to dig deeper when *you're* unsure, not to mechanically retry. Reproducible obvious bugs don't need three passes; ambiguous failures benefit from full instrumentation. Use judgment.

---

## Health gate

Cheap smoke check via `agent-browser eval` to catch whole-page regressions for free. **You decide when to run it.**

```javascript
(() => {
  const errorBanners = document.querySelectorAll(
    '.fi-banner-error, [role="alert"][aria-live="assertive"]'
  );
  const errorText = Array.from(errorBanners).map(b => b.textContent.trim());

  const consoleErrors = (window.__caughtErrors || []).slice();

  const bodyText = document.body.innerText;
  const hasUndefined = /\bundefined\b/.test(bodyText);
  const hasSomethingWentWrong = /something went wrong/i.test(bodyText);

  const path = location.pathname;
  const isAuthRedirect = path === '/app/login' || path === '/login';

  const layout = {
    hasMain: !!document.querySelector('main'),
    hasNav: !!document.querySelector('nav, [role="navigation"]'),
    hasH1: !!document.querySelector('h1'),
  };

  const livewireStuck = !!document.querySelector(
    '[wire\\:loading]:not([style*="display: none"])'
  );

  return {
    pass: errorBanners.length === 0
       && consoleErrors.length === 0
       && !hasUndefined
       && !hasSomethingWentWrong
       && (layout.hasMain || layout.hasNav)
       && !livewireStuck,
    errorBanners: errorText,
    consoleErrors,
    hasUndefined, hasSomethingWentWrong, isAuthRedirect,
    layout, livewireStuck, path,
  };
})();
```

### When the health gate fails

- Capture result object in `case<N>/iter-<N>/health-<step.id>.json`.
- **Do NOT auto-fail the case** — record it.
- All health-gate failures surface in Stage 3 (Report) inspection for human review.
- If a health-gate failure correlates with a STEP_FAIL on the same step → the page broke, real fail.
- If health passes but step fails → likely interaction bug (selector wrong, timing off) — consider iter 2.

### When to run

- ✅ First nav into a touched surface (e.g., into the company create page after a `CompanyResource` change).
- ✅ After any action that could break the page (form submit, action modal close, redirect-triggering button).
- ✅ After a tenant switch (URL slug changes — health-gate confirms the new panel loaded clean).
- ⚠️ Skip on incidental navigations (clicking back to dashboard at end of case).
- ⚠️ Skip when the page is intentionally in an error state (testing the 404 page itself).

### What it intentionally does NOT check

- Network errors → handled by Iter 3 HAR capture.
- Visual regression → deferred indefinitely.
- A11y violations → separate audit cadence.
- Slow page load → environment-dependent.
- Specific element presence → use case-specific assertions.

---

## Evidence types

Every verification step declares its `evidence_type` in the plan and emits a STEP_PASS/STEP_FAIL line during execution. The type is metadata — it tells the agent (during scoring) and human reviewers (post-hoc) what kind of evidence supports the verdict.

### Format

```
STEP_PASS|<step.id>|<evidence_type>|<artifact_path>
STEP_FAIL|<step.id>|<expected>→<actual>|<screenshot_path>
```

Examples:

```
STEP_PASS|step-2.2|snapshot_diff|case2/iter-1/diff-2.html
STEP_PASS|step-3.1|deterministic|case3/iter-1/db-3.1.json
STEP_FAIL|step-2.4|modal removed→modal still visible|case2/iter-1/fail-4.png
```

### The four types (strongest to weakest)

| Type | Description | Examples |
|---|---|---|
| `deterministic` | Boolean result from a query/script — no judgment | DB row count, URL match, console.error count, axe-core violation count |
| `a11y_ref` | Element identified via accessibility tree with stable ref | `@e1` matches `button[name=Submit]`, role=button, enabled=true |
| `snapshot_diff` | Before/after comparison shows expected change | DOM diff confirms `.fi-modal` removed; class list changed from X to Y |
| `screenshot_judgment` | Agent judges from a screenshot alone | "Looks correct in screenshot" |

### Guidance

- **Prefer the strongest evidence available** for each step. If you can write a DB query to confirm the outcome, do that instead of squinting at a screenshot.
- **When scoring case confidence, factor in evidence quality:** a case full of `deterministic` checks deserves higher confidence than one resting on `screenshot_judgment` alone.
- **A case where ALL steps are `screenshot_judgment` is suspicious** — note it in the verdict rationale so a human reviewer knows the evidence is soft. The aggregator won't penalize you automatically, but transparency matters.
- **`screenshot_judgment` is acceptable** for visual/aesthetic checks where no deterministic test exists — but label it honestly, don't dress up a screenshot judgment as a snapshot_diff.

### No penalty math

The aggregator does NOT multiply scores or apply ceilings based on evidence type. You pick confidence holistically, using evidence type as one input. See `report.md` for 0–100 ranges.

### Artifact paths

All artifacts under `case<N>/iter-<N>/`. The `artifact_path` in STEP_PASS is **relative to that directory**.

| Evidence type | Typical filename pattern |
|---|---|
| `deterministic` | `db-<step.id>.json` or `axe-<step.id>.json` |
| `a11y_ref` | `a11y-<step.id>.json` |
| `snapshot_diff` | `diff-<step.id>.html` |
| `screenshot_judgment` | `<step.id>.png` |

Iter-3 max-instrumentation adds: `network.har`, `console.log`, `video.mp4`, `dom-before.html`, `dom-after.html`.

---

## Hard rules (Run stage)

- **No fourth iteration. Period.**
- Run `validate_plan.py` before execution and fix all errors — never start execution on a structurally invalid plan.
- Emit STEP_PASS / STEP_FAIL for every verification step — never skip the line even if the result is obvious.
- Iter-2/3 artifacts must NOT overwrite iter-1 artifacts. Each iteration writes to its own `iter-<N>/` subdirectory.
- Health-gate failures are recorded, not auto-fail triggers. Correlate with step outcomes before drawing conclusions.
- Cases that pass after iter-2 adjustment are NOT flaky — they're "agent-misstep, real feature works." Cases that pass only on iter 3 with full instrumentation ARE flaky (`flaky=true`).
- A case where every iteration's STEP_FAIL is on the same step with the same actual output is a real bug — not flaky. Log clearly in the diagnosis.
