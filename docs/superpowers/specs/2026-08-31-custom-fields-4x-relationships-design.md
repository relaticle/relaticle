# Custom Fields 4.x: Relationships

Status: approved design, pending implementation plan.
Scope: relaticle/custom-fields 4.0 (substrate, fields, UX) plus the Relaticle core layer that consumes it.
Origin: discussion relaticle/relaticle#469 and the brainstorm that followed it.

## Decisions locked during brainstorming

1. 4.x is a housekeeping major. Relationships are the flagship; other breaking cleanups ship with it.
2. Storage: a typed edge ledger in a shared link table. Not jsonb sync, not dynamic DDL.
   Scored 90% vs 52% against dynamic DDL for our shared-table tenancy model.
3. UI: Attio-level experience, full field-management polish pass, dual flavor registry.
4. Cardinality: all four options, enforced in the database on Postgres.
5. One-way Record fields remain a first-class concept beside paired relationships (Attio parity).
6. Ownership: layered-A. The package stays standalone and owns the substrate.
   Relaticle core owns all AI intelligence. The commercial license gains a field-of-use clause at 4.0.
7. The relationship vocabulary (machine-readable codes) ships in 4.x, not later.
8. A separate definitions table exists. Fields are presentation slots of a definition.
9. Two ergonomics features ride the major rather than shipping as 3.x minors: through-relation
   table surfaces (issue #53, section 3.5) and bulk paste for choice options (section 3.3).
   #53 must wait, because Plan 1 rewrites the sort and filter paths it hooks into and the
   builder verb has to be named while "relationship" is being redefined. Bulk paste has no
   such dependency; it rides the major by decision, so the whole options editor changes once.
10. Select-option categories ship in 4.0, in the package, as settings.category on the option
    row (section 1.4). This closes relaticle/relaticle#556 with the category-per-option model
    (Linear's workflow-state shape). First consumer is reporting correctness only: won revenue,
    task completion, cycle direction. No transition event, no webhooks, no proactive assistant
    in this major. The change is additive and would fit a 3.x minor; it rides the major by
    decision, so the options editor changes once (with bulk paste) and the host sweep lands in
    the same Relaticle release as the substrate bump.

Key evidence, verified during research:

- Attio API: relationship attributes are paired record references with a partner pointer.
  Every value and link carries active_from, active_until, created_by_actor; show_historic
  returns full history. This is a temporal edge ledger and rules out per-attribute columns.
- Salesforce Force.com whitepaper: EAV flex columns plus a dedicated Relationships pivot table.
- Monica: relationship_types with forward and reverse names; one edge row read from both sides.
- Twenty, Baserow, NocoDB, Frappe: dynamic DDL, but always schema-per-workspace or per-site.
  AWS RDS documents catalog collapse at high table counts. Dynamic DDL is wrong for shared tables.
- Every modern flexible-model product (Attio, Copper, Airtable, Notion, monday, folk) ships
  auto-synced inverse with per-side naming. It is the modern default we currently lack.

## 1. Schema

### 1.1 custom_field_relationships (definition and vocabulary, one concept)

| Column | Notes |
|---|---|
| id, tenant_id | key types follow the host's published migrations, as all package tables; a database.key_type config honored by the two new tables ends the hand-edit step for ULID/UUID hosts |
| code | stable machine-readable semantic, e.g. reports_to; agents reason over this |
| from_entity_type, to_entity_type | may be equal (People to People) |
| cardinality | one_to_one, one_to_many, many_to_one, many_to_many; oriented from to to |
| from_field_id, to_field_id | nullable FKs to custom_fields; the presentation slots |
| is_symmetric | one field, canonical edge ordering (Spouse, Sibling) |
| timestamps | |

The field-count spectrum is the future-proofing:

- 2 fields: paired relationship fields with the Attio UX.
- 1 field: a one-way Record field (today's behavior, migrated onto the new storage).
- 0 fields: headless edge type. Created by Relaticle core for AI-inferred relationships.
  Never renders a form field. The package permits it; the CRM gives it meaning.

Display names live on custom_fields rows. Semantics live on the definition.
custom_fields.lookup_type is retired; the definition owns both ends.
Why not columns on custom_fields: relationship facts would be duplicated across two rows or
owned asymmetrically by one, and headless types would pollute every custom_fields consumer.

### 1.2 custom_field_links (the edge ledger)

| Column | Notes |
|---|---|
| id, tenant_id | tenant_id stamped by the writer (copied from the definition); TenantScope only filters reads |
| relationship_id | FK to definitions, cascade |
| from_entity_type, from_entity_id, to_entity_type, to_entity_id | polymorphic ends, key type via published migration |
| sort_order | preserves list order; parity with jsonb insertion order |
| active_from, active_until | current edge has null active_until; unlink closes, never deletes |
| created_by_type, created_by_id | polymorphic actor: user, agent, API token, import |
| source, confidence | nullable; user today, reserved for inferred edges |

Deletion semantics:

- Force-delete of a record: hard cascade sweep of all its edges via reverse indexes.
  This fixes the dangling-ULID bug that jsonb storage has today.
- Soft-delete: edges stay; readers skip trashed ends.
- Definition deleted: its links go with it (FK cascade); unpairing a field keeps the other side.

Indexes:

- (relationship_id, from_entity_id, active_until) and (relationship_id, to_entity_id,
  active_until); relationship_id is already tenant-scoped, so tenant_id needs no index prefix.
- (from_entity_type, from_entity_id) and (to_entity_type, to_entity_id) for cascade sweeps.
- A static partial unique over active edges on (relationship_id, from, to) prevents duplicate
  edges for every cardinality. Symmetric edges are canonicalized by the writer before insert,
  so the same index covers them.
- Per-end exclusivity (the one sides of one_to_one, one_to_many, many_to_one) cannot be a static
  index in a shared table: the constrained end varies per definition, and per-definition indexes
  would be dynamic DDL. It is enforced inside the writer transaction by a lockForUpdate on the
  definition row, which always exists and serializes writers per definition on every driver.
  No driver switch; the lock is skipped for many_to_many, where the unique index alone suffices.
  A DB constraint trigger can be added later without schema change.
- MySQL hosts get the same row-lock enforcement; the partial unique index (duplicate-edge wall)
  is Postgres and SQLite only, with a documented generated-column recipe for MySQL.

### 1.3 Retirement

json_value stops storing record links. The 4.0 migration ships as a step in the existing
custom-fields:upgrade framework: it creates a definition per record-type field and explodes
each json_value array into link rows (source = migration). The old value rows are kept until
a separate purge step runs after verification, so rollback stays trivial at every point.
Record fields flip to sortable and searchable. The single-value filter bug dies with the
storage that hosted it.

### 1.4 Option categories (relaticle/relaticle#556)

A select option is a free-text label. Nothing tells the system what an option means, so every
consumer that reasons about a status or a stage guesses from the label and breaks on rename or
translation. Relaticle spells `Done` four ways today and lost won-revenue reporting to a regex
in #549. 4.0 gives options a machine-readable category. Answers to the six deliverables #556
asked for:

1. Model: one category per option from a closed vocabulary, `OptionCategory`:
   `unstarted`, `started`, `completed`, `cancelled`. Nullable. Many options may share a
   category (Closed Won and Won Back are both `completed`), so there is no uniqueness rule.
   Rejected: a per-field semantic flag (repeats the four-places problem per field), a
   field-level id map (goes stale on option delete, breaks with two done-like states), and a
   locked seed (blocks the rename and translation that caused the complaint).
2. One mechanism covers both task status and opportunity stage. The two-outcome ending
   (Won versus Lost) fits without a second axis: Won is `completed`, Lost is `cancelled`,
   exactly as Linear separates completed from cancelled. Direction of movement follows for
   free: within a category, higher sort_order is forward; entering a terminal category is a
   close. `OptionCategory::isTerminal()` names the two terminal states.
3. Storage: `settings.category` on `custom_field_options`, added to
   `CustomFieldOptionSettingsData` beside `color`. JSON, no migration, no breaking change.
   Exposed for `FieldDataType::SINGLE_CHOICE` fields only; multi-choice options keep null.
4. Migration and backfill: the package ships nothing to backfill because it seeds no options.
   The host backfills by seeded name per tenant (section 4.1); options a tenant already
   renamed stay null and get set in the editor. Null means unknown, never "not done".
5. Setting it: a Select column beside the color picker in the options repeater, gated by a
   `FIELD_OPTION_CATEGORIES` feature flag mirroring `FIELD_OPTION_COLORS`, so hosts can hide
   it. New-workspace defaults come from the host's seed, not from the package.
6. Read API: `CustomField::optionsInCategory(OptionCategory)` and an `OptionCategory` cast on
   the settings data. Consumers stop matching labels; the host deletes every literal lookup.

Out of this section: a stage-transition event, outbound webhooks, and the assistant reacting
to a transition. Those were stress-tested in the same brainstorm and deferred; the category
is the primitive each of them would need, and it stands on reporting alone.

No sync engine exists anywhere. One row is read from both sides. Inverse drift, loop guards,
and 1:1 steal-cleanup bugs are structurally impossible.

## 2. Write and read paths

### 2.1 Write path

The payload contract is unchanged: custom_fields => [code => [ids]] through create/update.
Panel, REST API, chat approval, import, and MCP all keep working. Inside the trait,
record-type fields fork away from the value row:

1. Resolve field to definition and direction. Every target id must resolve to a row of the
   target entity type within the current tenant; a bad or foreign id is a validation error,
   never a stored edge.
2. Diff payload against active links. Removed: set active_until. Added: insert with
   sort_order, actor, source, and tenant_id copied from the definition. Unchanged links are
   never touched.
3. Null or empty array closes everything. Empty is a real value; it is never skipped.
4. Symmetric definitions canonicalize (least, greatest) before diffing.
5. Everything inside the surrounding save transaction.

Actor resolution is a package contract (LinkActorResolver, default auth user). Relaticle
binds its own resolver in chat-approval and job paths so agent writes are stamped as the agent.

Cardinality enforcement has two layers: a validation rule per cardinality for friendly errors,
and the partial unique index as the wall. A constraint race is caught and translated into a
validation error. The 1:1 steal is an explicit replace: close old, insert new, one transaction,
old edge auditable in history.

### 2.2 Read path

- getCustomFieldValue returns the same ordered id array as today.
- withCustomFieldValues and LookupPreloader batch-load links; no N+1 on tables.
- RecordColumn sorts via join on the target primary attribute; searches via whereExists;
  RecordFilter becomes an indexed whereExists. The orWhereJsonContains loop is deleted.
- History: links()->withInactive(), the Attio show_historic equivalent.

### 2.3 Events and management API

RelationshipLinkCreated and RelationshipLinkClosed carry the full edge payload.
CreateRelationshipDefinition (and delete/unpair siblings) create a definition plus its
0 to 2 fields transactionally, tenant-stamping every row. The Filament management UI uses
the same services. Both new models join the model-swap registry
(CustomFields::useRelationshipModel / useLinkModel) so ULID hosts can subclass them exactly
like the existing four.

Lifecycle rules:

| Event | Decision |
|---|---|
| Second record linked into a taken one-side | replace; displaced edge closed, auditable |
| One side of a pair deleted | unpair partner, keep its values |
| Cardinality changed later | allowed; multiple to single requires keep-first confirmation |
| Ends (entity types) changed later | forbidden, as today |

## 3. UI and UX

### 3.1 Relationship creation

Create-attribute flow, type Relationship: two entity cards with the cardinality selector
between them reading as a sentence; per-side associated attribute name inputs with smart
defaults; the sync banner as education; a symmetric toggle when both ends match, collapsing
to one name input; cmd+enter submits; one transaction. The machine code is auto-generated,
editable under an Advanced disclosure.

### 3.2 Record-side

Record chips with avatars and click-through; ordered chips with +N overflow, never a bare
count. Picker: debounced instant search, avatars, keyboard navigation, inline create-new.
Unlink on the chip; 1:1 steal confirms inline. Provenance on hover from the ledger
(changed by Chat Assistant, 2d ago).

### 3.3 Field-management polish

Searchable type-picker grid with icons and descriptions. Attio-style attribute table:
reorder handles, type icons, badges, inline activate; relationship pairs visually connected.
Educational empty states. Slide-over editing, cmd+enter, skeletons, dark-mode audit.

Bulk paste for choice options: a hint action on the options repeater opens a textarea, one
option name per line, appended as deduplicated rows. Today a 200-value vocabulary is 200
clicks through the add-option button. Names only; the color column is conditionally visible,
so a two-column paste format would read wrong on most tenants. State is appended through the
repeater (generated item keys, child-schema fill), never by writing option models directly,
so tenant stamping and sort_order keep working. A cap protects the Livewire payload.

### 3.4 Flavor registry

Two presentation flavors over one logic layer:

- polished (default): the experience above, custom Blade views where Filament has no primitive.
- native: same Livewire classes, stock Filament views, for hosts that want visual uniformity.

Config: ui.flavor plus a per-component override map. Rule: logic never forks; only views fork,
and only for the surfaces where polished is custom (configurator, chips and picker,
type-picker grid, attribute table). Critical browser paths run in both flavors in CI.
Cost accepted: 15 to 20 percent on UI work.

### 3.5 Through-relation table surfaces

Issue #53. A relation manager whose record does not itself hold custom fields cannot show the
related record's fields today: every column resolver type-hints HasCustomFields on the row
record. The table builder gains a through path:

    CustomFields::table()->forModel(Member::class)->through('member')->columns()

Scope wall: to-one relations only (BelongsTo, HasOne, MorphOne). Filters generalize to any
relation via whereHas, but sorting does not, because a to-many relation gives no single value
to sort by. The API rejects a to-many path rather than documenting the limitation later.

Mechanics, verified against Filament 5:

- Columns need no interface change. getStateUsing, sortable(condition, query) and
  searchable(condition, query) are idempotent public setters, so TableBuilder re-wraps the
  factory-built column exactly as it already re-wraps formatStateUsing.
- Filters do need one. modifyQueryUsing is protected with no public getter, and each filter
  type builds a different query, so the path must reach the filter's own make(). It rides the
  TableFilterInterface break in Plan 0 Task 1; the interface must not break twice in one major.
- Visibility conditions evaluate against the related record, not the row record. TableBuilder
  passes $record->{$relation} to BackendVisibilityService or columns silently mis-hide.
- Sorting a BelongsTo needs no join: entity_id correlates to the foreign key already on the
  main table. HasOne and MorphOne take one more hop.
- Eager loading is the host's job and must be documented. Column names are already dotted
  (custom_fields.code), so Filament's relationship inference contributes nothing here, and
  under strict lazy loading an N+1 is a 500, not a slow page.

Record-type columns through a relation are display and filter only in 4.0; sorting them means
joining the link ledger through a second relation hop, which is not worth the first release.

### 3.6 Verification

Every screen: agent-browser click-through, light and dark, mobile viewport, empty states,
before any done claim. Package UI stays themeable; no Relaticle styling leaks in.

## 4. Relaticle core layer

### 4.1 Surface migration at 4.0

- Chat bridge services survive (same id-array shape). Schema describer gains cardinality and
  relationship code. Proposal cards render chips with names. The silent-inverse-write concern
  from the old plan dissolves: one row is one fact; the other side is a read.
- MCP and REST API: payload unchanged; record fields become filterable and sortable in the
  API; Scribe regenerates; GetCrmSchemaTool exposes the vocabulary.
- ImportWizard: same payload path; imports stamp source = import.
- Activity log: one listener writes timeline entries on both records from one event.
- Option categories (section 1.4): the seeded Task status and Opportunity stage enums carry a
  category map beside their color map; `CreateTeamCustomFields` passes it through the
  migrator; a `custom-fields:backfill-categories` command (dry-run, per-team) sets categories
  on existing tenants by seeded name. Every literal `Done` lookup (`CompleteTask`,
  `DigestService`, `MyTasksService`, `GetCrmSummary`) moves onto `completed`, and won revenue
  returns to `GetCrmSummary` as the sum over `completed` stage options, reversing the #549
  removal on solid ground. Chat schema describer, `GetCrmSchemaTool`, and the REST option
  shape expose the category so agents and integrations stop guessing from labels too.

### 4.2 Intelligence (core-only, never packaged)

- GetRelatedRecordsTool in 4.0: depth-limited recursive-CTE traversal, grouped by relationship.
- Trust boundary unchanged: link writes ride the proposal/approval flow; the ledger stamps
  the actor either way.
- Headless-edge contract defined, not built: core will create 0-field definitions
  (works_with, source = ai_inferred, confidence) via the package service when email
  intelligence (#91) lands. 4.0 ships substrate and contract only.

### 4.3 Deferred

Inferred edges, confidence scoring, graph visualization, multi-hop UI. Gated on #91 and #495.

## 5. Housekeeping, licensing, rollout

### 5.1 Breaking bundle for the major

1. Links out of json_value; lookup_type moves to definitions.
2. allow_multiple and max_values collapse into definition cardinality for record fields.
3. New SYSTEM_RELATIONSHIPS feature flag, host-disableable.
4. UI flavor registry config surface.
5. PHP and Filament floor raised to current versions; legacy shims dropped where they block it.
   MySQL-family support continues with the documented enforcement caveat.
6. Itemized small-break sweep: produced by the 2026-09-01 architecture review. Substrate-
   independent items run first (2026-09-01-cf4-plan0-pre-substrate-housekeeping.md);
   substrate-dependent retirement runs last on the package side
   (2026-09-01-cf4-plan3-lookup-type-retirement.md).

### 5.2 Licensing

The commercial license gains a field-of-use clause at the 4.0 boundary: not usable to build
competing CRM products. 3.x terms stay as sold. Rationale: the AGPL side is already viral for
closed-source competitors; the clause closes the one real leak, which was the commercial
license itself.

### 5.3 Rollout

1. Develop on a 4.x branch; Relaticle wired via composer path repository during development.
2. Migration rehearsal against a copy of Relaticle production data (1,573 fields), verified in
   both directions (set and clear) through panel and API paths.
3. Tag 4.0.0; one Relaticle PR bumps the constraint and carries the surface migration;
   business-review of the full loop on the production-shaped stack (Horizon, Redis, Reverb).
4. Announce: package changelog, Relaticle release notes, follow-up on #469 once live.
5. Upgrade guide plus a MigrateRecordLinksStep in the existing custom-fields:upgrade
   framework: dry-run, a ValidateSchemaStep gate that fails the command until the step has
   run, and a separate post-verification purge step for the old value rows.

## Testing strategy

Package (Pest): definition creation for all three field counts; link add, remove, clear from
both sides; symmetric canonicalization; each cardinality enforced (validation layer and
constraint layer, including the race path); 1:1 replace; tenant isolation; force-delete
cascade; soft-delete read behavior; history reads; migration command on fixture data both
ways; both UI flavors on critical browser paths; option category round-trip through the
options editor, the settings cast, and the category scope, with the column hidden on
multi-choice fields and behind the flag.

Relaticle (Feature and Browser): the chat propose-approve-timeline loop for link writes;
API filter and sort on record fields; import with a relationship column; activity-log entries
on both records; GetRelatedRecordsTool traversal; task completion and won revenue on a
tenant whose Done and Closed Won options are renamed, proving no label lookup survives.

## Out of scope

- Attributes on the edge beyond the reserved columns (no started_on or notes UI in 4.0).
- Inferred edges and everything in 4.3.
- Graph visualization.
- A stage-transition domain event, outbound webhooks, and proactive assistant suggestions on
  transitions. Section 1.4 ships the category they need; they are separate decisions.

## Open items carried out of the brainstorm

- The single-value Record filter bug is untracked and unreproduced. It dies in 4.0, but 3.x
  users keep it. Decide whether to reproduce and fix in a 3.x patch or document it as known.
- The public reply on #469 promised paired fields with no date. A follow-up posts when 4.0 ships.
