# Custom Fields 4.0, Phase 3: Relationship Substrate

Status: approved design. Program: Custom Fields 4.0 (phase 3 of 5).
Plans: 2026-09-03-cf4-p3.1-substrate.md, 2026-09-03-cf4-p3.2-retirement.md.
Origin: discussion relaticle/relaticle#469 and the brainstorm that followed it.
Tracking: relaticle/custom-fields#210. UI for this substrate: the phase 4 spec.
Host consumption: the phase 5 spec.

## Decisions locked during brainstorming

1. Storage: a typed edge ledger in a shared link table. Not jsonb sync, not dynamic DDL.
   Scored 90% vs 52% against dynamic DDL for our shared-table tenancy model.
2. Cardinality: all four options, enforced in the database on Postgres.
3. One-way Record fields remain a first-class concept beside paired relationships, matching
   the leading relationship-first CRMs.
4. Ownership: layered-A. The package stays standalone and owns the substrate. Relaticle core
   owns all AI intelligence.
5. The relationship vocabulary (machine-readable codes) ships in 4.x, not later.
6. A separate definitions table exists. Fields are presentation slots of a definition.

Key evidence, verified during research (product names withheld; the repo is public):

- The leading relationship-first CRM's API models relationship attributes as paired record
  references with a partner pointer. Every value and link carries active_from, active_until,
  and the acting actor; a history flag returns full history. This is a temporal edge ledger
  and rules out per-attribute columns.
- A large multi-tenant CRM platform's published architecture: EAV flex columns plus a
  dedicated relationships pivot table.
- An open-source personal CRM: relationship types with forward and reverse names; one edge
  row read from both sides.
- The open-source dynamic-DDL products all run schema-per-workspace or per-site. Managed
  Postgres documentation describes catalog collapse at high table counts. Dynamic DDL is wrong
  for shared tables.
- Every modern flexible-model product ships auto-synced inverse with per-side naming. It is
  the modern default we currently lack.

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

- 2 fields: paired relationship fields with the paired-field UX (phase 4).
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

Phase 3 ends with the column dropped and the package fully on links, using stock Filament
for the record-field configuration (a definition-backed record config with a plain
cardinality select). Phase 4 replaces that stock form with the polished creation flow; it is
a presentation change, not a structural dependency.

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
- History: closed edges stay queryable (active_until not null); no dedicated scope beyond active() in 4.0.

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

## 3. Breaking changes owned by this phase

1. Links out of json_value; lookup_type moves to definitions and the column drops.
2. allow_multiple and max_values collapse into definition cardinality for record fields.
3. New SYSTEM_RELATIONSHIPS feature flag, host-disableable.
4. The record-select config keys are renamed to the record vocabulary.
5. The reflection helper into Filament internals is deleted once phase 3.1 has rewritten
   its two callers.

## Testing strategy

Package (Pest): definition creation for all three field counts; link add, remove, clear from
both sides; symmetric canonicalization; each cardinality enforced (validation layer and
constraint layer, including the race path); 1:1 replace; tenant isolation; force-delete
cascade; soft-delete read behavior; history reads; migration step on fixture data both ways,
purge step, idempotency; the stock record-config form round-trip.

## Out of scope

- Attributes on the edge beyond the reserved columns (no started_on or notes UI in 4.0).
- Polished UI (phase 4). Host surfaces (phase 5).

## Open item

The single-value Record filter bug is untracked and unreproduced. It dies in 4.0, but 3.x
users keep it. Decide whether to reproduce and fix in a 3.x patch or document it as known.

## Exit criterion

Package fully on links, lookup_type gone, stock-Filament record config working, migration and
purge steps green on fixture data, `4.0.0-alpha.3` tagged.
