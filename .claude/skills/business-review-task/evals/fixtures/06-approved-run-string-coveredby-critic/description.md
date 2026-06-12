# 06 — approved run with string covered_by + critic attestation (PR 336, 2026-06-12)

Locks three behaviors from the first real v3 PR-mode run:

1. **`covered_by` as a bare string** in `changed_surfaces` must behave like a
   one-element list — the latent bug iterated it into CHARACTERS, silently marking
   covered surfaces unreached (would cap a clean run at needs-human/blocked).
   This fixture's plan.md carries string `covered_by` values on all six surfaces.
2. **Coverage-critic attestation**: coverage-critic.json is present →
   `critic_ran: true`, `critic_missing_at_tier2plus: false`; label stays ai-approved.
3. A genuinely clean Tier-2 run with a non-empty, structured frontier (every item
   has `how_to_close`) aggregates to `ai-approved` and is NOT flagged as
   faked-breadth.
