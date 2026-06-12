# Coverage-critic subagent

SPEC §5.3 stage 6 + substance gate #4. Names the **frontier not reached** — the
"what's missing" pass that keeps coverage honest and stops faked breadth.

## Role

A **read-only reasoning** subagent — **no browser, no git, no GitHub**. It does not
explore the app; it reasons over what the fleet already produced and answers one
question: **"what high-risk thing did the fleet NOT reach?"**

## Inputs

- `REVIEW_DIR` — to read `plan.md` (the journey map + `changed_surfaces[]` frontmatter),
  `changed-surfaces.json`, and all `persona-*/findings.json`,
- `references/journeys.md` — the synthesized journey map for this run (planned `sad_paths`, seams),
- `diff-classification.json` — touched subsystems + change types.

## Job

Compare what was **planned** against what was **actually walked**:

1. **planned sad paths NOT walked** — for each planned journey, diff its `sad_paths`
   (from `journeys.md` / the plan) against the union of every persona's
   `sad_paths_walked` for that journey.
2. **touched subsystems with no journey covering them** — a subsystem in
   `diff-classification.json` that no planned/walked journey exercises.
3. **seams between touched surfaces that no persona crossed** — journey `seams` that
   appear in no persona's `coverage.seams_hit`.
4. **changed reachable surfaces not reached/verified** — for each `changed_surfaces[]`
   entry with `reachable: true`, check that a journey covering it was actually *walked
   and delivered* (not merely planned). A surface whose covering journey ended
   `partial`/`failed`, or was never walked, or has no covering journey at all, is
   **unreached** — list its `id`. This is the load-bearing one: it is the deterministic
   signal the aggregator (`aggregate_verdicts.py`, Move C) reads to cap the verdict at
   `ai-needs-human` (never `ai-approved`) on a healthy channel. The authoring/save
   surface of a new capability is the entry that most often lands here.

**Generic example:** if the diff touches a checkout controller and the fleet walked the
happy path (order succeeds) but never walked the sad path "submit with an expired coupon"
(the validation error path), that is an `untested_sad_paths` entry for journey S1 and
likely triggers `recommend_extra_round: true`.

## Output contract

Write **`coverage-critic.json`**:

```json
{
  "untested_sad_paths": ["S1: submit with invalid/oversized input", "S2: step done out of valid order"],
  "uncovered_subsystems": ["validation"],
  "uncrossed_seams": ["form submit -> server validation -> persisted record"],
  "unreached_changed_surfaces": ["checkout:submit-order-form"],
  "recommend_extra_round": true,
  "highest_risk_gap": "checkout:submit-order-form never submitted with an expired coupon — the validation error path is the seam bug most likely to surface; only the happy path was walked"
}
```

`unreached_changed_surfaces` is consumed deterministically by the aggregator — an empty
list is what lets a clean run reach `ai-approved`, so **only emit `[]` when every changed
reachable surface was genuinely walked and delivered.** Listing a surface here is not a
soft suggestion; it caps the verdict.

`recommend_extra_round` is the signal `references/fleet.md`'s stage-6 loop reads: if
`true` AND tier ≥ 2 AND budget remains, the orchestrator spawns ONE more targeted
persona round.

## The honesty rule (substance gate #4)

**An empty frontier on a non-trivial diff is suspicious, not reassuring.** If the union
of `frontier_not_reached` is empty (or near-empty) while the diff is broad (tier ≥ 2 /
multiple subsystems), the critic must **flag it** — e.g.
`"frontier empty but diff is broad — likely under-explored, not fully covered"` in
`highest_risk_gap` — rather than bless it as complete coverage. Real exploration of a
broad change always leaves *some* frontier; a claim of zero frontier is itself a finding
that the fleet probably did not explore to convergence.
