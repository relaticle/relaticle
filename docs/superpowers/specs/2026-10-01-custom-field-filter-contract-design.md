# Custom field filter contract

Status: draft for review, 2026-10-01. Verified against `origin/main` at `15eb516c3`.

## Goal

Filtering a list by custom field values returns the same, correct rows on every surface: the REST API, MCP, the in-app assistant (chat), and the Filament list tables. A caller can name a choice option by its label or its ID. A filter never silently returns nothing because of a typo.

Filtering stays separate from full-text search. Search finds a record by its text. Filtering narrows a list by field values. Search keeps excluding choice fields.

## Non-goals

- OR groups, relative dates, and text, number, or date filters in the UI. These belong to the follow-up spec (richer filter language), and saved views come after that.
- New operators in the UI. The UI keeps its "is any of" pickers.
- Moving `CustomFieldFilter` out of `app/Mcp/Filters`.
- Record (lookup) field filters on the API. The UI has `RecordFilter` and the API excludes `RECORD`. Noted, not changed.
- Searching rich-editor bodies. That is its own PR.

## Current state

| Surface | Engine | Choice operand | Gaps |
|---|---|---|---|
| UI tables | package `SelectFilter`, `TagsFilter`, `TernaryFilter`, `RecordFilter`, pushed by `InteractsWithCustomFields` on the five list pages | picked from the option list | multi-select and tags pickers match all of the picked values, not any |
| REST API | `App\Mcp\Filters\CustomFieldFilter` via `List*` actions | raw option ID | label returns nothing; `has_any` takes one value only; no empty or negation |
| MCP | same engine via `BaseListTool` | raw option ID | same as API; nothing tells the agent to pass IDs |
| Chat | same engine, after `CustomFieldsFilterTranslator` maps labels to IDs | label or ID | `has_any` takes one value only; no empty or negation |

Facts this design rests on:

- Choice values are stored as option ULIDs: `string_value` for single choice, a `json` array in `json_value` for multi choice.
- `whereJsonContains(col, [a, b])` compiles to `@>`, which requires every element. In Postgres, `'["a"]'::jsonb @> '["a","b"]'::jsonb` is false. The package's two multi-value filters (`SelectFilter` on json, and `TagsFilter`, which loops `whereJsonContains` inside one `whereHas`) have this bug.
- The app engine publishes `has_any` with operand type `string`, so `normalizeOperand()` rejects a list. On the API, MCP, and chat, `has_any` accepts exactly one value today.
- Tags-input values are free text written as-is (`acceptsArbitraryValues`), not option IDs. `CustomFieldInput::skipsOptionTranslation()` already encodes which fields translate labels on write.
- `get-crm-schema` already lists every choice option with its ID and label in `custom_fields`.
- `CustomFieldFilter::resolveFields()` filters on `active()` only. `CustomFieldFilterSchema::resolveFilterableFields()` also drops encrypted fields. The engine accepts encrypted fields the schema hides and runs `contains` over ciphertext.
- `CustomFieldOptionMap::idFor()` already accepts an option ID or a case-insensitive, trimmed label, and `isAmbiguous()` detects duplicate labels.
- Option saves and deletes clear the tenant's MCP schema cache (`AppServiceProvider::configureCustomFieldSchemaInvalidation`), so cached schemas may list option labels.
- Field codes are `alphaDash`, max 50 characters. No code can start with `$`.
- The UI filter panel lists active fields that are visible in the list and have a filterable type. The help page says "Searchable", which is wrong.
- `CustomFieldFilterTest` and the select cases in `OpportunitiesApiTest` save label strings such as `'Proposal'` as the stored value. That shape never occurs in real data, which is why the label bug went unnoticed.

## Decisions

### 1. Two engines, one contract

The UI keeps the package's filters. API, MCP, and chat keep `CustomFieldFilter`. A contract test runs both engines over one fixture written through the real write path and asserts identical record sets for the operators they share. The package gets the any-of fix upstream.

### 2. Operators by field type

New operators are marked with an asterisk.

| Field type | Operators |
|---|---|
| Single choice: select, radio, toggle-buttons | `eq`, `in`, `not_in`*, `is_empty`* |
| Multi choice: multi-select, checkbox-list, tags-input | `has_any`, `has_none`*, `is_empty`* |
| Email, phone, link | `has_any`, `has_none`*, `is_empty`* |
| Text | `eq`, `contains`, `is_empty`* |
| Number, currency, date, date-time | `eq`, `gt`, `gte`, `lt`, `lte`, `is_empty`* |
| Checkbox, toggle | `eq`, `is_empty`* |

Not filterable, as today: file-upload, record, textarea, rich-editor, and any encrypted field.

Conditions combine with AND, as today.

### 3. Choice operands accept a label or an ID

- The engine resolves each choice operand through `CustomFieldOptionMap` before it builds the query. It applies the write path's rule for which fields translate (moved from `CustomFieldInput` to `CustomFieldOptionMap::translates()`), so tags-input and lookup-backed fields match their raw stored strings. An exact option ID wins. Otherwise it matches a label case-insensitively after trimming.
- An unknown label or ID fails with a 422 on `filter` that names the field and lists its valid labels. It reuses the write path's `validation.custom_field.unknown_option` message.
- A label shared by two options fails with the existing `validation.custom_field.ambiguous_option` message.
- **Behavior change for API integrators:** a stale or mistyped option ID used to return an empty list with a 200. It now returns a 422. That is the intent: an empty list reads as a confident wrong answer.
- The canonical form is the option ID. Labels are an input convenience. Anything that persists a filter later (saved views) stores IDs, so renaming an option never breaks a saved filter.

### 4. `has_any` means any of

`has_any` and `has_none` take a list (a single value still works). A record holding only `[Hot]` matches `has_any [Hot, Warm]`. Implementation: one `whereHas` per condition, and inside it a grouped `where` of `orWhereJsonContains(column, [value])` per element. The `?|` jsonb operator is not used because `?` collides with PDO placeholders.

**Behavior change in the UI:** the multi-select picker (package `SelectFilter` on json values) and the tags picker (`TagsFilter`) switch from all-of to any-of. That matches Filament's own multiple `SelectFilter` and what "is any of" reads as in the filter chip.

### 5. `not_in` and `has_none` include empty records

`Status not_in [Done]` matches a task with no Status. `Tags has_none [Archived]` matches a company with no tags. Implementation: `whereDoesntHave` on a value row whose value is in the list.

### 6. `is_empty` takes a boolean

`is_empty: true` matches records where the field is empty. `is_empty: false` matches records where it is not. One operator replaces an `is_empty`/`is_not_empty` pair.

A field is empty for a record when any of these hold:

| Data type | Empty when |
|---|---|
| any | no value row for the field |
| scalar columns (string, integer, float, date, datetime, boolean, single choice) | the value column is null |
| text and string fields | the value is `''` (whitespace-only is not empty) |
| json arrays (multi choice, email, phone, link) | `json_value` is null or `[]` |

A value row holding a deleted option's ID counts as not empty. The package's `custom-fields:cleanup-orphaned-values` command owns orphans. Local data has none. See the open items.

### 7. One filterable-field predicate

`CustomFieldFilterSchema` exposes the predicate it already applies (active, type not excluded, not encrypted). `CustomFieldFilter::resolveFields()` uses the same predicate. A code the schema does not publish is an unknown code for the engine. That closes `contains` over ciphertext.

### 8. Limits

- At most 10 conditions per request, as today.
- At most 100 values in an `in`, `not_in`, `has_any`, or `has_none` operand. Today the lists are unbounded.

### 9. REST wire format

Unchanged shape: `filter[custom_fields][<code>][<operator>]=<value>`.

- Lists: `filter[custom_fields][status][not_in]=Done,Archived`.
- A label containing a comma uses the array form: `filter[custom_fields][tags][has_any][]=Hot, urgent&filter[custom_fields][tags][has_any][]=Warm`. Comma splitting now applies to labels, so the docs state this.
- Booleans: `filter[custom_fields][status][is_empty]=1`.

### 10. Room for the follow-up

OR groups, when they come, use a `$`-prefixed key in the filter object (for example `$any`). Field codes are `alphaDash`, so that key can never collide with a field. Nothing in this spec reserves or parses it yet.

## Components

### App

| File | Change |
|---|---|
| `app/Mcp/Filters/CustomFieldFilter.php` | label or ID resolution; `not_in`, `has_none`, `is_empty`; any-of `has_any`; list cap; shared field predicate; options eager-loaded in `resolveFields()` |
| `app/Mcp/Schema/CustomFieldFilterSchema.php` | new operators in `operatorsForType()`, list operands typed as arrays with `maxItems`; public filterable-field predicate |
| `app/Support/CustomFields/CustomFieldOptionMap.php`, `CustomFieldInput.php` | `translates(CustomField)` moves from `CustomFieldInput::skipsOptionTranslation()` so writes and filters share one rule |
| `app/Mcp/Tools/BaseListTool.php` | `filter` description names the operators, says choice values take a label or an ID, and points to the options in `get-crm-schema` |
| `app/Mcp/Resources/*SchemaResource.php` | `usage` string updated the same way |
| `packages/Chat/src/Services/Tools/CustomFieldsFilterTranslator.php` | deleted; the engine now does its job, and the project forbids dual paths |
| `packages/Chat/src/Tools/BaseReadListTool.php` | passes `custom_fields` straight to the engine and surfaces its validation message |
| `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php` | describes the new operators and the empty-inclusion rule |
| `lang/en/validation.php` | key for the unknown-option message, beside `custom_field.ambiguous_option` |
| `packages/Documentation/resources/content/docs/guides/mcp.md` | operator reference for list-tool filters |
| REST reference (Scribe, `config/scribe.php` and the V1 controllers) | filter parameters, operators, array form, 422 cases; today the reference promises filtering but documents no filter parameters |
| `packages/Documentation/resources/content/help/getting-started/find-anything-with-search-and-filters.md` | "Searchable" corrected to the real rule, after checking it in the UI |

### Package (`relaticle/custom-fields`)

| File | Change |
|---|---|
| `src/Filament/Integration/Components/Tables/Filters/SelectFilter.php` | json-valued fields match any picked option |
| `src/Filament/Integration/Components/Tables/Filters/TagsFilter.php` | match any picked tag |

The package changelog states the all-of to any-of change.

## Error handling

- All filter errors are `ValidationException` on `filter`. REST returns 422. MCP returns an error result. Chat returns the message to the model, which can correct its call.
- Messages name the field code, and for choice fields they list valid labels. They use `__()` keys.
- One wording on every surface. Unknown code and unsupported operator adopt chat's wording, which lists the valid choices; the MCP tests that pin the old engine wording update. Unknown option reuses the write path's message.

## Testing

Fixtures write option IDs through `saveCustomFieldValue($field, $option->id)`, never label strings.

1. **Fix the existing fixtures** in `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php` and the select cases in `tests/Feature/Api/V1/OpportunitiesApiTest.php` to store real option IDs.
2. **Engine behavior through the MCP list tool and the REST endpoint**, in those two files:
   - label, ID, and mixed-case label all match
   - unknown label and stale ID return 422 listing valid labels
   - ambiguous label is rejected
   - `has_any [a, b]` matches a row holding only `a` (the row the current code misses)
   - `not_in` and `has_none` include rows with no value
   - `is_empty` true and false for: no row, null scalar, `''` text, `[]` json
   - encrypted field code is rejected as unknown
   - a 101-value list is rejected
   - REST array form carries a label containing a comma
3. **Chat:** `tests/Feature/Chat/ListToolFilterTest.php` passes after the translator is removed, with one assertion updated from "not one of the options" to the shared write-path wording.
4. **Contract test**, new file `tests/Feature/Mcp/Filters/CustomFieldFilterContractTest.php` beside the engine's tests: one fixture of opportunities and companies on fields visible in the list. It applies the Filament table filter through Livewire on the list page and the same filter through the REST list, then asserts identical ID sets for single-choice `in`, multi-select `has_any`, and tags `has_any`.
5. **Package:** tests for any-of in `SelectFilter` and `TagsFilter`.
6. **Query plan:** `EXPLAIN ANALYZE` for `not_in` and `is_empty` on a seeded tenant with 50k records. `NOT EXISTS` should use the `(entity_id, custom_field_id)` index.

## Rollout

1. Package PR, review, release.
2. App PR: bump `relaticle/custom-fields` from `^3.10.0` to the release, plus all app changes and tests. The contract test fails until the bump lands, so the app PR waits for the release.
3. Release notes call out both behavior changes: the API's 422 on an unknown option, and any-of matching for the UI multi-select and tags pickers.

## Open items

- **Prod orphan count.** Run a read-only count of choice values whose option no longer exists before the app PR ships. The analytics clone does not copy `custom_field_values`. If the count is material, run the package's cleanup command first.
- **Email, phone, and link matching is case-sensitive** under `has_any`. Out of scope, noted for the follow-up.
- **`json_value` is `json` with no GIN index.** Fine at current scale. Revisit with the richer filter language.
