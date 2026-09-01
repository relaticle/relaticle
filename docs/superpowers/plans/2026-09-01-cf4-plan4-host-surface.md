# Custom Fields 4.0, Plan 4: Relaticle Surface Migration

> **For agentic workers:** REQUIRED SUB-SKILL: sdd-lean, task-by-task. Runs in the relaticle/relaticle repo on a feature branch, with the package wired via composer path repository until 4.0.0 tags. Relaticle gates apply (pint, rector, phpstan level 7, type coverage 100, targeted pest; `composer test:pest:full` before push).

**Goal:** Move every Relaticle consumer of record-field storage onto the 4.0 substrate in one release, so the composer bump, the data migration, and the surface changes land together. Evidence for each consumer: 2026-09-01 architecture review (P1.4) and the host-surface survey.

**Hard rule:** the activity-log listener (Task 4) ships in the same release as the substrate bump, or record-field changes silently vanish from timelines.

### Task 1: Wiring, published migrations, model subclasses

- composer path repository entry for `~/Herd/custom-fields-4x`; constraint `4.x-dev`.
- Publish the two new migrations and swap key types to ULID (`ulid('id')`, `foreignUlid` tenant and relationship_id, `ulidMorphs` for both ends), exactly like the published 3.x custom-fields migration.
- `app/Models/CustomFieldRelationship.php` and `app/Models/CustomFieldLink.php` subclasses (HasUlids, host scopes, observers as needed); register via `CustomFields::useRelationshipModel()` / `useLinkModel()` in `AppServiceProvider::configureModels()` beside the existing four.
- Arch test: extend the existing "never use package custom-fields models directly" rule to the two new models.
- Verify: definition + link round-trip through a Feature test with a real team tenant.

### Task 2: Actor resolver binding

- Bind `LinkActorResolver` to a host resolver: default auth user; in chat-approval (`PendingActionService::approve()`) and queued-job paths resolve the acting agent so ledger rows stamp the true actor.
- Verify: approval-path test asserts `created_by_type`/`created_by_id` is the agent, panel-path test asserts the user.

### Task 3: Chat bridge

- Record-field detection switches from `lookup_type !== null` to "has relationship definition": `packages/Chat/src/Services/Tools/CustomFieldsRequestValidator.php:120`, `packages/Chat/src/Services/ProposalEditor.php:197`.
- `CustomFieldsSchemaDescriber` starts describing record fields: relationship code, cardinality, target entity, so chat can finally set them (a field reachable in the Filament form must be settable from chat).
- Proposal cards render record chips with names via the existing name-resolution services; diff formatting for link changes (old set, new set).
- Sweep sibling Create/Update tools for the same change class before closing.
- Verify: full propose-approve-timeline loop on the production-shaped stack (Horizon, Redis queue, Reverb) per chat rules.

### Task 4: Activity log and timeline

- Listener on `RelationshipLinkCreated`/`RelationshipLinkClosed` writes timeline entries on BOTH records from one event, resolving display names.
- Retire the record-field path in `app/Observers/CustomFieldValueObserver.php` (record changes no longer flow through custom_field_values).
- Verify: linking A to B produces one entry on each side; unlink likewise; no duplicate entries from the old observer.

### Task 5: REST API and MCP

- Unblock RECORD in `app/Mcp/Schema/CustomFieldFilterSchema.php` (remove from EXCLUDED_TYPES); filters and sorts ride the package's link-backed whereExists.
- `app/Mcp/Tools/SearchTool.php:113-116`: the raw `json_array_elements_text(cfv.json_value)` search stops matching record ids after the purge; replace with a whereExists over links joined to the target's searchable attribute, or explicitly drop record fields from full-text search and note it.
- `GetCrmSchemaTool` exposes the relationship vocabulary (codes, cardinality, ends).
- Scribe regenerates; API docs show record fields as filterable/sortable.
- Verify: API filter + sort Feature tests on a record field, MCP search test.

### Task 6: ImportWizard

- `packages/ImportWizard/src/Data/EntityLink.php:108-133`: `fromCustomField()` resolves the target from the relationship definition, not `lookup_type`; multi-allowance from cardinality, not `typeData->supportsMultiValue` alone.
- Import writes stamp `source = import` on links (pass through the trait payload; LinkWriter source parameter).
- Verify: import fixture with a relationship column links correctly and stamps source.

### Task 7: GetRelatedRecordsTool

- New chat tool: depth-limited recursive-CTE traversal over custom_field_links, grouped by relationship code, read-only, `lookup: true` support per chat tool rules.
- Verify: Feature test over a two-hop fixture graph; prompt allowlist entry.

### Task 8: Rehearsal and release

- Migration rehearsal against a copy of production data (1,573 fields): run `custom-fields:upgrade`, verify reads in both directions (set and clear) through panel and API, then the purge step, then re-verify.
- Business-review of the full loop on the production-shaped stack.
- Tag 4.0.0; one Relaticle PR bumps the constraint and carries all of the above; follow-up post on relaticle/relaticle#469; package changelog + upgrade guide.
