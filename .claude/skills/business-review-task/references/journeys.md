# Journeys — synthesized from the diff, anchored by Relaticle CRM priors

The unit of review is a **journey**: a user-facing value arc the diff affects. The
`journey-synthesizer` agent derives this run's journeys from (1) the diff's touched
surfaces (`classify_diff.py` output) and (2) the project profile. The **priors below are
anchors, not a ceiling**: synthesize a journey for every changed surface no prior covers,
and skip priors the diff doesn't touch.

## Relaticle journey priors (J-ids; use as templates when the diff touches their arc)

| id | arc | personas | characteristic seams + sad paths |
|---|---|---|---|
| J1 | Sign up / log in → land on team dashboard | guest→team-owner | wrong password, unverified email, post-login redirect target, session expiry mid-form (419 retry) |
| J2 | Create/edit a CRM record (company/person/opportunity/task/note) with custom fields | team-owner, team-member | required-empty, special chars/emoji, custom-field round-trip per type, double-submit, cancel button |
| J3 | Pipeline: move opportunity through stages (kanban) | team-owner | drag to stage, server error rollback, empty stage, stage with WIP rules |
| J4 | Import CSV → records (wizard) | team-owner | invalid rows surfaced (REG-003 empty name), type coercion, dry-run vs commit, resume mid-wizard, custom-field columns |
| J5 | AI chat: prompt → tool proposal → approve → record mutated | team-owner | insufficient credits, reject proposal, batch proposals, approval after reload, composer alive after N messages (REG-005), credit ledger debit pairing |
| J6 | Team & tenancy: invite member, switch team, cross-team isolation | team-owner, team-member | deep-link to other team's record → 403/404, custom-field isolation (REG-004), member-permission boundaries |
| J7 | REST API consume: token → CRUD + QueryBuilder filters | api-consumer | 401 without token, tenant scoping, validation 422, custom-fields read shape `{id,label}`, write-ignore behavior |
| J8 | Billing/credits: balance display → consumption → transaction history | team-owner, sysadmin | zero balance block + upgrade prompt, ledger pairing, **enum-rendering surfaces (REG-001)** |
| J9 | Sysadmin panel: login → resource lists render → record visibility | sysadmin | non-sysadmin denied, new enum cases render in tables (REG-001), minimal depth (internal panel) |
| J10 | Notifications/real-time: action → toast/broadcast received | team-owner | Reverb connected, toast content, auto-dismiss, stacking |

## Synthesis rules (same engine as universal — full schema in `agents/journey-synthesizer.md`)

1. **Start from touched surfaces**; group surfaces in the same value arc (form +
   controller/action + result view = ONE arc).
2. **Name the value via the profile** ("a team-owner imports companies and sees them in
   the list", not "ImportWizard changed").
3. **Happy path first, then sad paths** — ≥1 sad path per journey (≥2 at Tier 3),
   anchored on the **seam** (the hand-off where two surfaces meet under an unusual
   condition). Always include the unhappy branch of any NEW conditional in the diff.
4. **Assign personas + subsystem tokens**; mark `synthesized: true` (validator then skips
   catalog-id resolution; a prior-based journey may keep its J-id).
5. **Author → persist → consume**: when the diff touches an authoring surface (a form,
   wizard, composer), the happy path MUST drive the real save and confirm persistence —
   never substitute a pre-seeded record for the thing under test. Consumption-only
   coverage of an authoring change is the dodge `validate_plan` exists to reject.
6. **Regression checks ride journeys**: every matched `regression-checks.json` entry is
   attached to the journey covering its surface (or gets a dedicated micro-journey).
   The plan frontmatter lists `regression_checks: [{id, journey, status}]`.

## Generic sad-path heuristics (apply whichever fit; the seam decides)

empty/invalid/oversized input · steps out of order · destructive-then-recreate ·
permission/tenant boundary crossing (mandatory — multi-tenant app) · duplicate/
double-submit · abandon-and-return mid-flow · the unhappy branch of every new conditional.

## Hard rules

- Every journey's `surfaces` must contain ≥1 real touched file/route from the diff —
  no faked breadth. Don't synthesize arcs the product doesn't have.
- Infra-only diff (no user-facing surface): synthesize none, emit `no_surface_reason`,
  verdict path is an honest `ai-needs-human` (no test-suite fallback exists).
- Every journey ≥1 sad path; a happy-only journey has not been reviewed.

Output: `$REVIEW_DIR/journey-map.json`, folded into plan frontmatter, validated by
`scripts/validate_plan.py` before the fleet launches.
