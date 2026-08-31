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
| id, tenant_id | key types host-configurable, as all package tables |
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
| id, tenant_id | |
| relationship_id | FK to definitions, cascade |
| from_entity_type, from_id, to_entity_type, to_id | polymorphic ends, key type configurable |
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

- (tenant_id, relationship_id, from_id) and (tenant_id, relationship_id, to_id),
  partial WHERE active_until IS NULL.
- (from_entity_type, from_id) and (to_entity_type, to_id) for cascade sweeps.
- A static partial unique over active edges on (relationship_id, from, to) prevents duplicate
  edges for every cardinality. Symmetric edges are canonicalized by the writer before insert,
  so the same index covers them.
- Per-end exclusivity (the one sides of one_to_one, one_to_many, many_to_one) cannot be a static
  index in a shared table: the constrained end varies per definition, and per-definition indexes
  would be dynamic DDL. It is enforced inside the writer transaction: pg_advisory_xact_lock
  scoped to the relationship on Postgres, lockForUpdate elsewhere, plus the validation layer.
  Refined during plan writing; a DB constraint trigger can be added later without schema change.
- MySQL hosts: app-level enforcement plus a documented generated-column recipe.
  Postgres gets the database-enforced wall.

### 1.3 Retirement

json_value stops storing record links. The 4.0 migration creates a definition per record-type
field, explodes each json_value array into link rows (source = migration), then deletes those
value rows. Record fields flip to sortable and searchable. The single-value filter bug dies
with the storage that hosted it.

No sync engine exists anywhere. One row is read from both sides. Inverse drift, loop guards,
and 1:1 steal-cleanup bugs are structurally impossible.

## 2. Write and read paths

### 2.1 Write path

The payload contract is unchanged: custom_fields => [code => [ids]] through create/update.
Panel, REST API, chat approval, import, and MCP all keep working. Inside the trait,
record-type fields fork away from the value row:

1. Resolve field to definition and direction.
2. Diff payload against active links. Removed: set active_until. Added: insert with
   sort_order, actor, source. Unchanged links are never touched.
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
0 to 2 fields transactionally. The Filament management UI uses the same services.

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

### 3.4 Flavor registry

Two presentation flavors over one logic layer:

- polished (default): the experience above, custom Blade views where Filament has no primitive.
- native: same Livewire classes, stock Filament views, for hosts that want visual uniformity.

Config: ui.flavor plus a per-component override map. Rule: logic never forks; only views fork,
and only for the surfaces where polished is custom (configurator, chips and picker,
type-picker grid, attribute table). Critical browser paths run in both flavors in CI.
Cost accepted: 15 to 20 percent on UI work.

### 3.5 Verification

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
6. Itemized small-break sweep to be produced during plan writing from a repo audit.

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
5. Upgrade guide plus custom-fields:upgrade-links command with dry-run and rollback notes.

## Testing strategy

Package (Pest): definition creation for all three field counts; link add, remove, clear from
both sides; symmetric canonicalization; each cardinality enforced (validation layer and
constraint layer, including the race path); 1:1 replace; tenant isolation; force-delete
cascade; soft-delete read behavior; history reads; migration command on fixture data both
ways; both UI flavors on critical browser paths.

Relaticle (Feature and Browser): the chat propose-approve-timeline loop for link writes;
API filter and sort on record fields; import with a relationship column; activity-log entries
on both records; GetRelatedRecordsTool traversal.

## Out of scope

- Attributes on the edge beyond the reserved columns (no started_on or notes UI in 4.0).
- Inferred edges and everything in 4.3.
- Graph visualization.

## Open items carried out of the brainstorm

- The single-value Record filter bug is untracked and unreproduced. It dies in 4.0, but 3.x
  users keep it. Decide whether to reproduce and fix in a 3.x patch or document it as known.
- The public reply on #469 promised paired fields with no date. A follow-up posts when 4.0 ships.
