# Custom Fields 4.0, Phase 2: Option Categories

Status: approved. Program: Custom Fields 4.0 (phase 2 of 5).
Plans: 2026-09-03-cf4-p2.1-option-categories-package.md (package),
2026-09-03-cf4-p2.2-option-categories-host.md (Relaticle consumers).
Closes relaticle/relaticle#556 once both plans land. Tracking: relaticle/custom-fields#210.

## Problem

A select option is a free-text label. Nothing tells the system what an option means, so every
consumer that reasons about a status or a stage guesses from the label and breaks on rename or
translation. Relaticle spells `Done` four ways today and lost won-revenue reporting to a regex
in relaticle#549.

## Decision

Select-option categories ship in the package as `settings.category` on the option row, with
the category-per-option model (the workflow-state shape used by modern issue trackers). The
first consumer is reporting correctness only: won revenue, task completion, cycle direction.
The change is additive, and it ships in 4.x only, no 3.x backport: the options editor
changes once (with bulk paste, phase 4) and the host sweep lands in the same Relaticle
release as the substrate bump.

## Answers to the six deliverables #556 asked for

1. Model: one category per option from a closed vocabulary, `OptionCategory`:
   `unstarted`, `started`, `completed`, `cancelled`. Nullable. Many options may share a
   category (Closed Won and Won Back are both `completed`), so there is no uniqueness rule.
   Rejected: a per-field semantic flag (repeats the four-places problem per field), a
   field-level id map (goes stale on option delete, breaks with two done-like states), and a
   locked seed (blocks the rename and translation that caused the complaint).
2. One mechanism covers both task status and opportunity stage. The two-outcome ending
   (Won versus Lost) fits without a second axis: Won is `completed`, Lost is `cancelled`.
   Direction of movement follows for free: within a category, higher sort_order is forward;
   entering a terminal category is a close. `OptionCategory::isTerminal()` names the two
   terminal states.
3. Storage: `settings.category` on `custom_field_options`, added to
   `CustomFieldOptionSettingsData` beside `color`. JSON, no migration, no breaking change.
   Exposed for `FieldDataType::SINGLE_CHOICE` fields only; multi-choice options keep null.
4. Migration and backfill: the package ships nothing to backfill because it seeds no options.
   The host backfills by seeded name per tenant (plan 2.2); options a tenant already renamed
   stay null and get set in the editor. Null means unknown, never "not done".
5. Setting it: a Select column beside the color picker in the options repeater, gated by a
   `FIELD_OPTION_CATEGORIES` feature flag mirroring `FIELD_OPTION_COLORS`, on by default,
   host-disableable. New-workspace defaults come from the host's seed, not from the package.
6. Read API: `CustomField::optionsInCategory(OptionCategory)` and an `OptionCategory` cast on
   the settings data. Consumers stop matching labels; the host deletes every literal lookup.

## Host consumption (Relaticle, plan 2.2)

The seeded Task status and Opportunity stage enums carry a category map beside their color
map; `CreateTeamCustomFields` passes it through the migrator; a
`custom-fields:backfill-categories` command (dry-run, per-team) sets categories on existing
tenants by seeded name. Every literal `Done` lookup (`CompleteTask`, `DigestService`,
`MyTasksService`, `GetCrmSummary`) moves onto `completed`, and won revenue returns to
`GetCrmSummary` as the sum over `completed` stage options, reversing the #549 removal on
solid ground. Chat schema describer, `GetCrmSchemaTool`, and the REST option shape expose the
category so agents and integrations stop guessing from labels too.

## Testing

Package: category round-trip through the options editor, the settings cast, and the
category scope, with the column hidden on multi-choice fields and behind the flag; the
multi-option case (two `completed` options) is the point of the scope test.

Relaticle: task completion and won revenue on a tenant whose Done and Closed Won options are
renamed, proving no label lookup survives; backfill command on a fixture tenant, dry-run and
real.

## Out of scope

A stage-transition domain event, outbound webhooks, and proactive assistant suggestions on
transitions. This phase ships the category primitive they need; they are separate decisions.

## Exit criterion

Package: enum, settings cast, scope, editor column, docs. `4.0.0-alpha.2` tagged on the package half. Host: seeds, backfill, zero literal
status lookups, won revenue restored, category exposed in chat, MCP, REST.
