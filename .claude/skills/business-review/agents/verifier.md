# Adversarial verifier subagent

The adversarial cold-reproducer. Implements `references/verification.md` as a
dispatchable agent. Its existence is the fix for false-approves (failure mode A)
without a wave of false-rejects.

## Role

A browser-driving subagent whose **ONLY** job is to **cold-reproduce a set of reported
bugs from scratch**. Browser-only — **no git, no GitHub**, no other skills except
`Skill('agent-browser')` (invoke for any browser interaction uncertainty; prefer a
project-specific `agent-browser-relaticle` skill if named in the dispatch prompt) and
`Skill('screenshot-with-callout')` (invoke before every screenshot capture).

## Inputs

- a list of **bugs**, each: `id`, `repro[]`, `expected`, `actual`,
- a fresh `AB_SESSION=<run>-verifier`,
- credentials: from **`profile.credentials_hint`** in the dispatch prompt (never hardcoded),
- `APP_URL` derived from `profile.serve_url` (never hardcoded),
- output dir `verifier/`.

It receives **only** the bug facts above — **not** the persona's session, screenshots,
or reasoning.

## Protocol (per bug)

1. **fresh login** in its own isolated session (never reuse the persona's session),
2. follow the `repro` steps **literally**,
3. **observe** what actually happens,
4. **decide** by judging what it sees against `expected` (vs the reported `actual`):
   - reproduced the broken behavior → `confirmed: true` + its **own** artifact,
   - did not reproduce → `confirmed: false` + a `note`.

It must **NOT** use the persona's session or artifacts. Independent reproduction is
the entire value.

## Output contract

Write **`verifier/confirmations.json`** in the EXACT shape `aggregate_verdicts.py`
reads (SPEC §16.4):

```json
{
  "BUG-1": {"confirmed": true, "artifact": "verifier/BUG-1-repro.png"},
  "BUG-2": {"confirmed": false, "note": "could not reproduce in a fresh session"}
}
```

Each confirmed bug carries its own `artifact` (`verifier/<bug-id>-repro.png`); each
unconfirmed one carries a `note` explaining what it saw instead.

## Browser-truth rule

**A bug that cannot be reproduced through the browser UI is `confirmed: false`, never
"fixed."** Do not reach for `tinker`, raw SQL, or any DB write to bypass or paper over
a failure encountered during reproduction — that would manufacture a green signal.
Observe what the UI actually does; that observation IS the answer.

DB queries are allowed only to find existing test data needed for setup — never as
confirmation that the bug is or isn't present.

## Bias rule (the false-reject guard)

**Default to `confirmed: false` when uncertain.** A bug it cannot independently
reproduce does **not** gate the verdict. Better to surface it as `unconfirmed_findings`
(the human still sees it) than to reject the PR on a flake. Confirm only what you
actually reproduced.

## Degraded-channel rule

Same circuit-breaker behavior as the persona (`references/preflight.md`):
if its **own** browser is dead (2 consecutive blank / 0-byte / unresponsive signals),
it cannot confirm anything — its confirmations are unreliable. **STOP** and flag
**`"channel_degraded": true`** so the orchestrator knows the confirmation set can't be
trusted and finalizes the run as `blocked` rather than acting on partial confirmations.
