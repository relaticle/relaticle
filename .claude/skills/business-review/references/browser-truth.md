# Browser-truth — the UI is the only proof

The fix for failure mode E (manufactured-green). Read before any case runs.

## Rules

1. **Every verification is observed through the real browser UI** (visible DOM/state),
   never DB-query-only. A DB query (`mcp__laravel-boost__database-query`) is allowed
   ONLY to (a) find existing seed-data candidates for setup, or (b) corroborate
   something already shown on screen — never as the sole evidence that "it works."

2. **Never use `tinker`, raw SQL, or any direct-DB write to fix, bypass, or paper over
   an error** hit during a journey. An error is a FINDING -> adversarial verification
   -> if confirmed it gates the verdict. You are proving the thing works through the
   UI, not manufacturing a green run.

3. **Data setup prefers browser creation.** `tinker` seeding is allowed ONLY for genuine
   prerequisites that are NOT the thing under test, and even then it is setup — never a
   remedy for a failure. Seeded prerequisites still get the `br-rel-<run>-<persona>-` prefix.

3b. **Establishing a login is SETUP, not a "fix."** Authentication is the most basic
   prerequisite — it is *not* "the thing under test" for a non-auth journey, so getting a
   working session is permitted setup (rule 3), not a forbidden DB-write. If the documented
   `credentials_hint` is missing or does not work (common when the local DB is a sanitized
   **production snapshot**), bootstrap one test login via the project's OWN tooling — a
   framework user-create command, a seeder, or, as a last resort, a single direct store
   write — namespaced with `br-rel-<run>-<persona>-`. Prefer the **least-invasive** option:
   on a production-like snapshot, reset a single throwaway/sanitized account rather than
   mutating a real named admin, and never touch real user data beyond the one bootstrap
   account. A broken/absent login is a **credentials Context gap** (`environment.md` §5) →
   at worst `ai-needs-human`; it is **never** a degraded channel / `blocked` — the browser
   still works (`preflight.md` §3).

4. **Tenant switching is browser-only** — the in-app Switch Accounts UI, never `tinker`
   (tinker tenant-switch silently breaks the session -> persistent 403s).

## Enforcement

The capability-attestation gate (report.md Gate 6, SPEC §10.6) rejects any verdict label
on a user-facing diff with zero browser artifacts, and stamps every code-trace-only claim
`NOT browser-verified`. DB-only evidence does NOT satisfy the attestation.

## Worked examples

- Move-in 500s on the no-grant sad path -> screenshot the 500, log a bug, send to the
  verifier. DO NOT `tinker` a grant onto the project to make the wizard proceed.
- Need an existing tenancy with voided HAP to test J4 -> first try to reach that state
  through the UI; if it must be seeded, seed only the prerequisite tenancy, never the
  result you are trying to verify.
