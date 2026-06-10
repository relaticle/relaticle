# Risk Rubric

Score every diff: **Risk = Likelihood × Impact**, both 1-5, total 1-25. The score gates matrix breadth and auto-fix permission.

## Likelihood (1-5)

Pick the anchor that best matches the diff:

| Score | Anchor |
|---|---|
| 1 | Copy/CSS/icon change; variable rename; pure formatting |
| 2 | Validation rule tweak, simple refactor, single-field addition |
| 3 | New feature in existing model, new Filament action, new Livewire method |
| 4 | Cross-cutting change (touches 2+ resources), reactive form logic, computed fields |
| 5 | Schema migration, new model, auth/policy/scope changes, MCP/API surface change |

## Impact (1-5)

| Score | Anchor |
|---|---|
| 1 | Cosmetic bug, single page, recoverable |
| 2 | Minor UX glitch, single user, recoverable |
| 3 | Feature broken, productivity hit |
| 4 | Data integrity issue, multi-team confusion, partial outage |
| 5 | Data loss, auth bypass, multi-tenant leak, security/compliance |

## High-impact tag override

Any path match in the diff forces **Impact = 5**, regardless of the anchor. Run these greps against the diff:

| Pattern | Why |
|---|---|
| `app/Policies/**` | Authorization logic |
| `Gate::define`, `->authorize(`, `Auth::user()` | Authorization wiring |
| `BelongsToTeamCreator`, `tenant_id`, `team_id`, `belongsToTeam(` | Multi-tenant boundary |
| Global scope additions/removals | Tenant scoping |
| `app/Models/{Company,People,Opportunity,Task,Note}.php` | The 5 custom-fields-bearing models — exposed on every surface |
| `database/migrations/**` with `drop`, `dropColumn`, `dropIfExists` | Destructive schema |
| `app/Mcp/Tools/Base*Tool.php` | Changes propagate to all 29 entity tools |
| `app/Filament/Resources/**Policy*` | Filament-level role enforcement |
| `Sanctum`, `Fortify`, `Socialite` | Auth subsystems |
| Password / login / 2FA / email-verification paths | Auth surface |
| `relaticle/custom-fields` integration code | 22-field-type engine |

## Risk-score → action gates

| Score | Tier | Matrix breadth | Auto-fix permission (ceiling) |
|---|---|---|---|
| 1-5 | smoke | 1 persona (Default), happy path + 1 negative, single surface | Disabled — report only |
| 6-11 | light | 2 personas, 1-2 tours, basic data nasties, surface-relevant | High only, never in auth/tenancy/migration code |
| 12-19 | medium | 3-4 personas (Cross-Tenant Spy if model touched), 2-3 tours, full nasties on changed inputs | High only |
| 20-25 | deep | Full persona roster, all relevant tours, **multi-tenant checklist mandatory**, MCP + API parity check | High only, **never** in auth/tenancy/migration code |

The auto-fix permission column is a **ceiling**. At smoke tier, even a High finding becomes "report only" — auto-fix is suppressed because the risk score was so low to begin with that surfacing a High finding signals the score itself was wrong.

## Surface scoping (independent of score)

| Path pattern | Surfaces added to matrix |
|---|---|
| `app/Filament/Resources/**`, `app/Filament/Pages/**` | Filament UI |
| `app/Livewire/**` | Livewire component |
| `app/Http/Controllers/Api/**`, `routes/api.php`, `app/Http/Resources/**` | REST API |
| `app/Mcp/**`, `routes/ai.php` | MCP server |
| `app/Models/{Company,People,Opportunity,Task,Note}.php` | All four surfaces |
| `app/Policies/**` | Every surface that consumes the policy (grep callers) |
| `database/migrations/**` | `migrate:fresh --seed` smoke + every surface touching the changed tables |
| `app/Actions/**` | Every caller of the action (grep) |
| `resources/views/**`, `resources/css/**`, `resources/js/**`, `app/View/**`, `tailwind.config.*`, `vite.config.*`, `app/Filament/Components/**`, `app/Filament/Theme*` | Filament UI + Livewire (visual change — Supermodel mandatory; consult `references/visual-probes.md`) |

A change to `TaskPolicy.php` therefore expands to Filament UI + REST API + MCP because the same policy gates all three.

## Output

The skill must print at the top of every report:

```
Risk: <L>×<I>=<Total> → <tier>
- Likelihood <L>: <reason>
- Impact <I>: <reason, including high-impact tag matches if any>
- Surfaces: <comma-separated list>
- Auto-fix ceiling: <Disabled|High only|High only excluding auth/tenancy/migration>
```
