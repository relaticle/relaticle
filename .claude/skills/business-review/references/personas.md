# Personas — Relaticle roster, lenses first, fleet second

A persona = a real Relaticle role + a behavior profile. **Personas are primarily LENSES**
the walker applies; parallel persona *subagents* are a Tier-3 tool, not the default
(field evidence: fleets repeatedly died on session capacity limits, and sequential
persona-aware walks found the same bugs). An inline sequential walk that honestly applies
each brief is a first-class mode — record which mode ran in the report.

## Roster (drawn from `profile.user_roles`)

| persona | who | goal flavor | characteristic mistakes |
|---|---|---|---|
| `team-owner` | the CRM's daily driver, owns the team | get pipeline/records/imports done fast | double-click submit, paste formatted text/emoji into fields, abandon-and-return mid-wizard |
| `team-member` | invited, fewer permissions | work records without owning the team | deep-link to things they shouldn't see, early-Enter on forms |
| `sysadmin` | internal operator at the sysadmin panel | inspect transactions/conversations/users | filter/sort heavy tables, open every enum-rendering surface (REG-001) |
| `api-consumer` | a script with a Sanctum token | CRUD via REST + QueryBuilder | wrong/expired token, unallowed filters, attempts to write read-only fields (custom_fields) |
| `newbie-owner` | just signed up, no data | onboard from empty state | wrong field order, typo-then-fix, gets lost (UX-lens rich) |
| `integrity-breaker` (Tier 3 only) | adversarial | break boundaries | cross-tenant deep links, double-fire money/destructive actions, mutate the immutable (open-modal-and-cancel only), oversized/hostile input |

~90% goal-directed-with-characteristic-mistakes; a dash of monkey-input for crash-hunting
only. Random clicking is noise, not testing.

## Per-tier selection

| Tier | Selection |
|---|---|
| 0 | 1 persona inline — the role closest to the touched surface |
| 1 | 1–2 personas inline |
| 2 | 3 distinct archetypes (subagents allowed, inline fallback fine) |
| 3 | 3–5 including `integrity-breaker` — parallel subagents IF capacity allows |

**Capacity check before spawning** (Tier 2+): spawn ONE persona subagent first; if it
errors out on usage/session limits, run the remaining briefs inline sequentially and
state that in the report. A dead subagent is never silently absorbed (`fleet.md`) — and
losing independence matters for verification (SKILL.md gate 6), not for walking.

## Credentials & sessions

Credentials come from `$REVIEW_DIR/project-profile.json.role_credentials` — resolved and
verified by Stage 0 (`environment.md` §2), never hardcoded, never hunted mid-journey.
A role with no obtainable login → its journeys become structured frontier items.

Each persona: own `AB_SESSION=<run>-<persona>` on EVERY agent-browser call; own data
namespace `br-rel-<run>-<persona>-` on every created record. Tenant switching via the
in-app switcher ONLY. Drive the app per the **`agent-browser-relaticle`** skill (panel
URLs, login flow, Filament/Livewire `$wire` patterns); generic `agent-browser` is the
fallback when that skill's pattern fails twice (then self-heal it — `environment.md` §3).
