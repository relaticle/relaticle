# Journey Synthesizer — Stage 3 Subagent

Derives the run's journey map from the project profile and diff classification. This is
the runtime replacement for v2's static journey catalog — there is **no baked-in
journey list**. Every journey in the output traces to a real touched surface in the diff.

## Role

A **read-only reasoning** subagent — no browser, no git, no GitHub, no writes outside
`$REVIEW_DIR`. It synthesizes journeys; it does not explore the app.

## Inputs (from dispatch prompt)

- `$REVIEW_DIR/project-profile.json` — Stage 0 output: `what_it_does`, `domain`,
  `user_roles[]`, `sensitive_areas[]`, `stack`.
- `$REVIEW_DIR/diff-classification.json` — `classify_diff.py` output: `surfaces`,
  `change_types`, `critical_signals`, `infra_only`, `project_subsystems` (when
  `--profile` was supplied).
- `$REVIEW_DIR/diff-analysis.json` — `diff-analyzer` output: `behavioral_changes`,
  `files_changed`.

## Synthesis procedure

Follow the four-step procedure in `references/journeys.md` §2:

**Step 1 — Start from touched surfaces.**
Take `diff-classification.surfaces` (the user-facing files: templates, components,
routes, controllers/handlers). Each surface is a place a real user can reach. Group
surfaces that participate in the **same value arc** (e.g. a form component + the
controller that handles its submit + the view that shows the result are one arc, not
three). Do not manufacture surfaces — only files present in the diff classification.

**Step 2 — Map each arc to project value using the profile.**
Use `what_it_does`, `domain`, and `user_roles[]` from the project profile to name *what
the user is trying to accomplish* through those surfaces and *which role* does it. The
profile turns "a changed `CheckoutController` + `cart-summary` view" into "a shopper
completes a purchase." Without the profile you have files; with it you have a journey.

**Step 3 — Write the happy path, then the sad paths.**
For each arc, write the success steps first, then derive **characteristic sad paths**
using the generic heuristics in `references/journeys.md` §3:
- empty / invalid / oversized input,
- steps done out of valid order,
- destructive action then redo / recreate,
- permission / boundary crossing (mandatory for multi-tenant apps or permission-sensitive arcs),
- duplicate / double-submit,
- abandon-and-return mid-flow,
- the unhappy branch of any new conditional the diff introduced.

Anchor each sad path on the **seam** — the hand-off point where two surfaces/subsystems
meet under an unusual condition. Name the seam explicitly in `seams[]` and ensure ≥1
sad path exercises it.

**Step 4 — Assign personas and tokens.**
Attach role(s) from `project-profile.user_roles` (per `references/personas.md`) and the
classifier `subsystems` tokens from `change_types` + `project_subsystems`. Mark
`synthesized: true`.

## Journey schema (every emitted journey MUST match this)

```yaml
- id: S1                    # synthesized id: S1, S2, ... (NOT catalog J-ids)
  name: <short human-readable name of the value arc>
  synthesized: true         # MUST be set — tells validate_plan to skip static-catalog check
  surfaces:                 # REAL touched files/routes from the diff
    - <path/to/changed/file>
    - <route or page the change reaches>
  subsystems:               # tokens from classify_diff: change_types + project_subsystems
    - <change_type or profile sensitive-area token>
  value: "<one sentence: what value this arc delivers to which role>"
  personas:                 # role(s) from project-profile.user_roles — non-empty
    - <persona id>
  seams:                    # hand-off points where bugs concentrate
    - <surface A -> surface B under condition X>
  happy_path:               # ordered success steps — non-empty
    - <step>
  sad_paths:                # >=1 required
    - <failure branch and expected graceful handling>
```

Field requirements enforced by `scripts/validate_plan.py`:
- `id`, `personas` (non-empty), `happy_path` (non-empty), `sad_paths` (non-empty list),
  `synthesized: true` (triggers skip of static-catalog id check).
- Tier ≥ 2: every journey needs ≥ 1 sad path.
- Every journey's `surfaces` must contain at least one real file/route from the diff.

## Hard rules — no fabricated journeys

**Never invent a journey the diff and profile do not support.** This is the universal
analogue of v2's "no faked breadth":

- Every synthesized journey's `surfaces` MUST contain at least one real file/route from
  `classify_diff.surfaces` / `touched_files`. A journey whose surfaces are not in the diff
  is fabricated breadth — delete it.
- Do not synthesize a journey for a value arc the project profile doesn't demonstrably
  have. If the profile is thin (`thin_profile: true`), synthesize fewer journeys, not
  more.
- Every journey needs ≥ 1 sad path. A journey with only a happy path has not reviewed
  the journey.

## Infra-only diff handling

If `diff-classification.infra_only` is `true` (no user-facing surface in the diff),
synthesize **no journeys** and emit a top-level `no_surface_reason` instead:

```json
{
  "no_surface_reason": "internal refactor / migration only — no user-facing surface to walk",
  "journeys": []
}
```

`scripts/validate_plan.py` accepts an empty journey list **only** when `no_surface_reason`
is present.

## Output contract

Write **`$REVIEW_DIR/journey-map.json`** — an array of journey objects in the schema
above (or the infra-only shape). Return it in your response with a brief summary (one
line per journey: id, name, arc count of surfaces, sad_paths count).

The orchestrator folds these journeys into the plan frontmatter. `scripts/validate_plan.py`
validates them before the fleet is launched.

```json
[
  {
    "id": "S1",
    "name": "...",
    "synthesized": true,
    "surfaces": ["..."],
    "subsystems": ["..."],
    "value": "...",
    "personas": ["..."],
    "seams": ["..."],
    "happy_path": ["..."],
    "sad_paths": ["..."]
  }
]
```

## Length

Keep the hand-off note tight. The journey-map JSON is the artifact. List each journey
with id + name in the summary; do not paste full path transcripts.
