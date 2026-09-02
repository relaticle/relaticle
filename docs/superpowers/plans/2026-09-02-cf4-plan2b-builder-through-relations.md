# Custom Fields 4.0, Plan 2b: Through-Relation Table Surfaces

> **For agentic workers:** REQUIRED SUB-SKILL: sdd-lean, task-by-task. Runs on the `4.x` branch AFTER Plan 1 (substrate) and Plan 0 Task 1 (which carries the `?string $through` parameter on `TableFilterInterface`). Independent of Plan 2; either order. Package quality gate per task: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `vendor/bin/pest --type-coverage --min=100.0`, targeted pest.

**Goal:** Implement spec section 3.5. Let a Filament table whose row record does not itself hold custom fields display, filter and sort the custom fields of a to-one related record.

**Issue:** relaticle/custom-fields#53. A `RegistrationRelationManager` cannot show `Member`'s custom fields today: every column resolver type-hints `HasCustomFields` on the row record (`src/Filament/Integration/Concerns/Tables/ConfiguresColumnState.php:20`), and the sort subquery correlates `custom_field_values.entity_id` to the row model's key (`ConfiguresSortable.php:33`).

**API:**

```php
CustomFields::table()
    ->forModel(Member::class)
    ->through('member')
    ->columns();
```

`forModel()` keeps naming the model the fields come from. `through()` names the relation from the row record to that model. The verb is `through`, not `forRelation`: in 4.0 "relationship" means a custom-field edge, and the builder must not overload it.

**Why it waits for Plan 1:** Plan 1 rewrites `RecordColumn` sorting/searching and `RecordFilter` onto link-backed `whereExists`. Building through-relation support against `json_value` first would mean building the same three query paths twice.

## Global constraints

- **To-one relations only:** `BelongsTo`, `HasOne`, `MorphOne`. Filters would generalize to any relation via `whereHas`, but sorting cannot: a to-many relation offers no single value to sort by. Reject a to-many path with a clear exception rather than shipping a half-working column set.
- **Validate at query time, not build time.** The builder never learns the row model (`forModel()` names the field source). Every sort/search/filter closure receives the `Builder`, so `$query->getModel()->{$relation}()` yields the `Relation` instance there; the state closure has the record. Validate in both places and throw a package exception naming the model, the relation and why it was rejected.
- **No new interface parameters beyond Plan 0 Task 1.** Columns are re-wrapped from `TableBuilder` using idempotent public setters. Filters use the `$through` parameter that Plan 0 already added.
- Package conventions per Plan 1's global constraints: `strict_types`, final, fully typed, config-driven table and column names.

### Task 1: `through()` on the builder and the relation resolver

- `through(string $relation): static` on `TableBuilder` (not `BaseBuilder`; forms and infolists render a single record and have no equivalent need).
- A small final resolver class that, given a row model and the relation name, returns the `Relation` instance and classifies it: supported to-one kinds, or a rejection reason. One place decides support, so the four call sites cannot drift.
- Exception type: a package exception, message naming model, relation and reason (missing relation, to-many, related model does not implement `HasCustomFields`).
- Tests: resolver classification per relation kind; each rejection path.

### Task 2: Column state, sorting and searching

- In `TableBuilder::columns()`, when a through path is set, re-wrap the factory-built column after `create()`, exactly as the existing `formatStateUsing` re-wrap does (`TableBuilder.php:44`):
  - `getStateUsing(fn ($record) => $record->{$relation}?->getCustomFieldValue($field))`, null-safe so a row with no related record renders empty rather than erroring.
  - `sortable(condition, query:)` rebuilt for the relation. `BelongsTo` needs no join: `entity_id` correlates to the foreign key already on the row table. `HasOne`/`MorphOne` take one more hop through the related table.
  - `searchable(condition, query:)` likewise, through the same correlation.
  - All three are idempotent public setters (`HasCellState::getStateUsing`, `CanBeSortable::sortable`, `CanBeSearchable::searchable`), so re-calling replaces what the per-type component set. No reflection.
- **Visibility:** `TableBuilder::columns()` passes the row record to `BackendVisibilityService::isFieldVisible()`. With a through path it must pass `$record->{$relation}`, or conditions on the related model's fields silently mis-hide columns. This is the easiest bug to miss here; cover it with a test where a through column is conditionally hidden.
- Record-type columns through a relation are display and filter only in 4.0. Sorting one means joining the link ledger through a second relation hop; mark the column not sortable rather than emitting a wrong ORDER BY.
- Tests: state resolution including a null related record; sort assertions for `BelongsTo` and `HasOne` (assert row order, not SQL text); search; the visibility case; a record-type column asserting it is not sortable.

### Task 3: Filters through the relation

- Pass the through path into `FieldFilterFactory::create()` and on to each filter's `make(..., ?string $through)`, the parameter Plan 0 Task 1 added. This is why filters differ from columns: `modifyQueryUsing` is protected with no public getter and each filter type builds a different query, so the builder cannot wrap what it cannot read.
- Each in-package filter (`SelectFilter`, `TagsFilter`, `TernaryFilter`, `RecordFilter`) wraps its own existing constraint in `whereHas($relation, ...)` when a path is given. The inner closure is unchanged, so there is one query shape per filter, not two.
- Tests: each filter type, with and without a through path, asserting the filtered set.

### Task 4: Eager loading, docs, and browser verification

- Eager loading is the host's job and must be documented at the top of the feature's docs page, with the `modifyQueryUsing(fn ($q) => $q->with('member.customFieldValues.customField'))` recipe. Filament's relationship inference gives nothing here because column names are already dotted (`custom_fields.code`), and under strict lazy loading (as in Relaticle) an N+1 is a 500, not a slow page.
- Assert the query count in a test over a multi-row table with eager loading applied; without it, assert the documented failure is the lazy-load exception, not silent N+1.
- Docs page: the API, the to-one scope wall and why, the eager-load recipe, the record-type sorting caveat.
- Browser: a relation-manager fixture table showing through columns, sorted and filtered, light and dark, plus the empty state where the related record is null.
- Close #53 referencing the docs page when 4.0 ships.
