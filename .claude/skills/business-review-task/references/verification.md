# Adversarial cold-repro verification

The cross-check that kills false-approves (failure mode A) **without** a wave of
false-rejects. Spec §5.2 stage 5 + §5.7 gate #5.

## 1. Principle

**No bug enters the verdict without an independent cold-reproduction.**

- A **persona REPORTS** bugs (in its `bugs[]`).
- The **verifier CONFIRMS** them, from scratch, in a fresh session.
- Only **confirmed** bugs gate the verdict — they become `confirmed_blockers` and can
  flip the label to `ai-rejected`.
- **Unconfirmed** bugs are reported as `unconfirmed_findings` and **never reject**.
  They are surfaced to the human as "persona-reported, not reproduced", not buried.

## 2. Cold-repro protocol

The verifier agent (`agents/verifier.md`) is dispatched with **ONLY** each bug's:

- `repro` steps,
- `expected`,
- `actual`.

It does **NOT** receive the persona's browser session, the persona's screenshots, or
the persona's reasoning. For each bug it:

1. starts a **fresh** `agent-browser` session (`AB_SESSION=<run>-verifier`),
2. does a **fresh** login,
3. follows the `repro` steps **literally**,
4. observes what actually happens and judges it against `expected`,
5. records the outcome:
   - **confirmed** → captures its **own** artifact (`verifier/<bug-id>-repro.png`),
   - **not reproduced** → records a short `note` explaining what it saw instead.

## 3. Output contract

The verifier writes **`verifier/confirmations.json`**, keyed by bug id — the EXACT
shape `aggregate_verdicts.py` reads (Plan 1 Task 5):

```json
{
  "BUG-1": {"confirmed": true,  "artifact": "verifier/BUG-1-repro.png"},
  "BUG-2": {"confirmed": false, "note": "could not reproduce in a fresh session"}
}
```

`aggregate_verdicts.py` reads this file: any bug with `confirmed: true` lands in
`confirmed_blockers` (→ `ai-rejected`); everything else lands in
`unconfirmed_findings` (→ does not gate).

## 4. Why this makes "strict" safe

The spec's tension is strict-vs-lenient: lean-strict-always risks false-rejects;
lean-lenient keeps letting failure mode A through. Adversarial verification resolves
it: because **only adversarially-confirmed bugs reject**, the skill can simultaneously

- lean **strict on what survives** (a confirmed blocker rejects, no hedging), AND
- **show the coverage frontier** (be honest about what wasn't reached),

without flooding the user with false rejects. Strict on the confirmed, transparent
about the rest.

## 5. Anti-pattern (do NOT do this)

The verifier must **not "trust" the persona's screenshot** or take the persona's word.
It reproduces from scratch, every time. A bug it cannot independently reproduce is
`confirmed: false` — **even if the persona's evidence looks convincing.** Convincing
evidence is not reproduction. The whole point is a second, independent pair of eyes;
inheriting the first agent's state defeats it.

## 6. Self-review boxing (mandatory independence)

If the reviewing session AUTHORED the diff (same conversation wrote the code), the
verifier MUST run as a fresh subagent — the author cold-reproducing its own bugs is not
independent. If no independent pass is possible (subagent capacity exhausted), say so in
the report and cap Tier ≥ 2 verdicts at `ai-needs-human` with that reason in
`decision_needed`. Field evidence: two consecutive self-authored runs (PR 326/332) lost
their independent reader to capacity limits and one shipped an `ai-approved` miss that
crashed production.
