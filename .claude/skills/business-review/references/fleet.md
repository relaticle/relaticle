# Fleet orchestration

SPEC §5.2 stage 4 + §5.5. How the orchestrator spawns and coordinates the parallel persona
fleet and collects findings.

## 1. Roles — orchestrator vs personas

- **Orchestrator (the main process).** Owns git, the diff, the journey map, the tier,
  and the report. It stays **single-process** so env + git state stay coherent. It spawns
  personas, collects their findings JSON, runs verification + the coverage-critic, and
  writes the report.
- **Personas (parallel subagents).** Each plays ONE persona, drives its **own** browser
  session, and does **browser-only** work — **never touches git**. Subagents that read
  git state get a snapshot frozen at spawn time; personas read no git at all. Personas
  read back only each persona's `findings.json`.

## 2. Spawn protocol

For **tier ≥ 1**, dispatch the selected personas (selection rules in `personas.md`) as
**parallel subagents** (`agents/persona.md`), each handed:

- its **brief**: goal + behavior profile,
- its **assigned journeys**: the subgraph slice from the journey map (`journeys.md`),
- `AB_SESSION=<run>-<persona>` (isolated browser session),
- data prefix `br-rel-<run>-<persona>-` (greppable, non-colliding records),
- credentials (from `profile.credentials_hint` or the Stage 1 context-gap answer).

**Tier 0 collapses to a single in-process persona pass** — no subagent spawn. One
archetype, the touched journey, happy + one obvious sad path.

## 3. Concurrency cap

**≤ 3 live browser sessions at once** (SPEC §7). Excess personas **queue** and run as
slots free up. Parallel headless browsers exhaust local resources past ~3 and sessions
get flaky — a flaky session produces degraded signals that would wrongly trip the circuit
breaker.

## 4. Traversal

Each persona walks its journeys:

1. **happy-path first** — establish the value arc works. For a journey that requires
   creating a record, the happy path MUST actually **create → persist → confirm**: drive
   the real workflow UI, **save and confirm it persisted**, then verify the downstream
   effect. Do not substitute a pre-seeded record for the thing under test — the save step
   is the surface under review.
2. then **inject its characteristic sad paths** — the journey's `sad_paths` × the
   persona's behavior profile.
3. **explore each journey to convergence** — keep going until no *new* behavior surfaces,
   then stop.
4. **record coverage honestly** — in `findings.json`, list the `covers_surfaces` actually
   reached + delivered, and the **frontier not reached**. A `covers_surfaces` entry
   assigned but not reached/delivered is what the coverage-critic turns into
   `unreached_changed_surfaces`. Never mark an authoring surface reached if only its
   consumption was walked.

At every navigation point, run the **health gate**: confirm the page is not a 5xx/error
page, has no console errors, and actually rendered. A failed health check is a finding,
surfaced for adversarial verification — not silently skipped. Run the **UX lens
continuously** (`ux-lens.md` heuristics) as the persona moves.

## 5. Evidence (the capability-attestation gate)

**Every browser-derived claim needs a real artifact** — a screenshot, a `snapshot_diff`,
or an `a11y_ref`. A claim with no artifact is not a verified claim. A run with zero
browser artifacts on a user-facing diff cannot produce a verdict label (it is `blocked`;
see `preflight.md`).

For the browser-truth rules (no console/DB to fix or bypass, errors are findings, never
manufacture green) see `browser-truth.md` — read it before any case runs.

Screenshot discipline: invoke `Skill('screenshot-with-callout')` **per shot**. For UI
layer quirks, prefer a project-specific `agent-browser-*` skill if one exists; otherwise
use generic `agent-browser`. (See `checks-matrix.md` for per-UI-layer check hints.)

## 6. Return

Each persona returns the findings schema written to **`persona-<name>/findings.json`**.
The orchestrator reads **only the findings JSON** — not persona browser transcripts.

If a persona subagent **crashes or returns null**, the orchestrator continues with the
rest and **records the gap in the coverage frontier** — it never silently drops a missing
persona.

## 7. Coverage-critic loop (stage 6)

After the fleet returns, the orchestrator runs the **coverage-critic agent**
(`agents/coverage-critic.md`). If the critic names a **high-risk untested seam** AND
**tier ≥ 2** AND budget remains, the orchestrator may spawn **ONE more targeted persona
round** (tier-capped — never unbounded). Then proceed to verification and the report.

## 8. Safety guards — conditional on the project profile

The three guards that make the real parallel fleet safe.

### 8.1 Tenant-isolation guard — **only if `profile.is_multi_tenant == true`**

When the project is multi-tenant, each persona logs into its own `AB_SESSION=<run>-<persona>`
and switches tenant **via the in-app Switch Accounts UI only** — never via a console/REPL
command (console tenant-switch silently breaks the session → persistent 403s; hard-won
gotcha). Only a journey explicitly designed to test cross-tenant isolation intentionally
crosses tenants.

**If `profile.is_multi_tenant == false` (single-tenant app):** skip this guard. There is
no tenant context to switch; no in-app tenant switcher exists. A single-persona session
with standard login is sufficient.

### 8.2 Financial-safety / no-mutation guard — **if `profile.has_financial_ops == true` OR the diff hit destructive `critical_signals`**

When financial operations are present, a persona only *creates* records inside its own
greppable namespace `br-rel-<run>-<persona>-`. For any action mutating production-like or
shared financial records (paid transactions, real invoices, committed ledger entries),
the persona **opens the confirm modal and cancels it**, verifies via the browser that
state is unchanged, and moves on — never commits.

**If `profile.has_financial_ops == false` AND no destructive critical signal:** the
lighter default still holds — don't mutate production-like or shared records, and don't
clean up test data (leave it for inspection). The strict "open-and-cancel" discipline for
financial actions is relaxed but the general "don't break shared data" rule is not.

No cleanup afterward (leave data for inspection, consistent with v1/v2 behavior).

### 8.3 Browser-only, no-git subagents — **always**

Personas read **no git at all** — they receive their brief and journey subgraph as plain
input. The orchestrator owns 100% of git state and reads back only each persona's
`findings.json`. Personas never touch git. This is unconditional regardless of project
type.

## Browser-session isolation (parallel ≤3)

`agent-browser` supports isolated sessions (`--session <name>`, `AGENT_BROWSER_SESSION` env,
`session list`). Each persona MUST run with its own `AB_SESSION="<run>-<persona>"` and pass
`--session "$AB_SESSION"` (or export `AGENT_BROWSER_SESSION="$AB_SESSION"`) on **every**
`agent-browser` call — that isolation is what makes the ≤3 parallel model safe. Preflight runs
`agent-browser session list` once to confirm support; if `--session` is unsupported (older binary),
fall back to **sequential** persona execution and say so in the report (a documented degraded mode,
not a silent default).

## Gated state with no account → frontier, not silent skip

If a journey needs a role/plan/tenant state for which `profile.role_credentials[]` has no usable
login, emit a structured frontier item — `{item, why_unreached: "no <state> account reachable",
how_to_close: "provide a login for <role> / seed a <plan> account / run as <owner>"}` — instead of
skipping. This feeds `verdict-final.json.frontier[]` and the report's decision gate.
