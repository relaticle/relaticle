# MCP Custom Field Filtering, Relationship Includes & Aggregation

**Date:** 2026-03-15
**Status:** Draft
**Scope:** Enhance MCP List tools with custom field filtering, relationship expansion, and CRM summary aggregation

## Problem

The MCP server supports basic CRUD (20 tools) but agents can't:
1. Filter records by custom field values ("deals over $50K closing this quarter")
2. Expand relationships inline ("show opportunity with company name included")
3. Get aggregate summaries ("what's our pipeline value?")

This blocks natural CRM conversations and forces agents into multi-call pagination loops.

## Goals

- Enable filtering by any custom field with typed operators
- Allow relationship expansion in a single response
- Provide aggregate CRM summaries without pagination
- Zero new tools -- extend existing BaseListTool + add one MCP Resource
- Maintain backwards compatibility -- all new parameters are optional
- Keep token footprint small -- concise schemas, no bloat

## Non-Goals

- Logical operators ($or, $not) -- v1 is AND-only
- Nested path filtering (filter companies by a person's attribute)
- JSONB migration for custom field storage
- Write support for custom fields via API (separate initiative)
- Global cross-entity search tool

## Research Summary

- **Attio** uses POST query endpoints with 9 operators ($eq, $gt, $gte, $lt, $lte, $contains, $starts_with, $ends_with, $in). Their MCP server does NOT expose raw query power -- uses domain-specific tools instead. We prefer raw query power for developer audience.
- **HubSpot MCP** has crm_search_objects with operators: EQ, NEQ, LT, LTE, GT, GTE, BETWEEN, IN, NOT_IN, HAS_PROPERTY, CONTAINS_TOKEN.
- **PostgreSQL + EAV**: whereHas with EXISTS subqueries performs well on PostgreSQL (smart optimizer). No need for raw JOINs.
- **Spatie QueryBuilder v6**: AllowedFilter::custom() with invokable Filter classes is the clean integration point.
- **Token efficiency**: Keep schemas compact. AI agents infer from property names + types. Don't over-document.
- **Laravel MCP**: shouldRegister() can conditionally hide tools. JsonApiResource shared between API and MCP -- single source of truth.

## Design

### 1. Custom Field Filtering in BaseListTool

#### Schema Changes

Add optional `filter`, `sort`, and `include` parameters to BaseListTool schema. Built dynamically from the team's active custom fields at tool registration.

```json
{
  "search": "Acme",
  "company_id": "abc",
  "filter": {
    "amount": {"gte": 50000},
    "close_date": {"gte": "2026-04-01", "lte": "2026-06-30"},
    "stage": {"in": ["Proposal", "Qualified"]}
  },
  "sort": {"field": "amount", "direction": "desc"},
  "include": ["company", "contact"],
  "per_page": 15,
  "page": 1
}
```

When `filter`, `sort`, and `include` are omitted, behavior is identical to current tools. Fully backwards compatible.

#### Operator Mapping

Each custom field data type maps to specific operators:

| Field Data Type | Operators | JSON Type | Value Column |
|---|---|---|---|
| STRING (text, email, phone, link) | eq, contains | string | string_value |
| FLOAT (currency) | eq, gt, gte, lt, lte | number | float_value |
| NUMERIC (number) | eq, gt, gte, lt, lte | integer | integer_value |
| DATE | eq, gt, gte, lt, lte | string (date) | date_value |
| DATE_TIME | eq, gt, gte, lt, lte | string (datetime) | datetime_value |
| BOOLEAN (checkbox, toggle) | eq | boolean | boolean_value |
| SINGLE_CHOICE (select, radio) | eq, in | string / array | resolved via CustomFieldValue::getValueColumn() -- may be string_value or integer_value depending on option key type |
| MULTI_CHOICE (multiselect, tags) | has_any | string | json_value (uses whereJsonContains) |
| FILE | excluded from filtering | -- | -- |
| TEXT (textarea, rich editor, markdown) | excluded from filtering | -- | -- |

**Excluded field types:** Encrypted fields (`settings.encrypted === true`), FILE fields, and long TEXT fields (textarea, rich_editor, markdown_editor) are excluded from the filterable schema. Encrypted values are ciphertext -- comparison operators are meaningless on them.

Native fields (name, company_id, contact_id, created_at) are included alongside custom fields -- the agent sees one flat list.

#### Schema Generation

New class `CustomFieldFilterSchema` builds the JSON schema dynamically:

1. Reads team's active custom fields (cached 60s, same as existing schema resources)
2. Excludes encrypted fields, FILE fields, and long TEXT fields
3. For each remaining field, maps data type to allowed operators
4. For SINGLE_CHOICE: resolves value column via `CustomFieldValue::getValueColumn()` to determine correct JSON type
5. Generates strict operator schema per field
6. Includes native filterable fields (name, created_at, etc.)

#### Request Construction in BaseListTool.handle()

Currently `BaseListTool.handle()` passes a `$filters` array to `$action->execute(filters: $filters)`. The actions internally construct `new Request(['filter' => $filters])`. For filter/sort/include to work with Spatie QueryBuilder, `handle()` must switch to passing a full Request object.

**New flow in handle():**

1. Extract `search` and entity-specific filters (company_id, etc.) → these become `filter[name]`, `filter[company_id]` in the Request (existing behavior, just in Request form)
2. Extract `filter` object → nest under `filter[custom_fields]` key in Request (Spatie sees one allowed filter named `custom_fields`)
3. Extract `sort` object `{"field": "amount", "direction": "desc"}` → convert to Spatie format string `"-amount"` (prefix `-` for desc) → set as `sort` in Request
4. Extract `include` array `["company", "contact"]` → join as `"company,contact"` → set as `include` in Request
5. Skip adding filter/sort/include keys to Request if empty or not provided (avoids Spatie validation issues)
6. Pass constructed `Request` as `$request` parameter to `$action->execute(request: $request)` -- all 5 actions already accept this optional parameter

**Example constructed Request input:**
```php
new Request([
    'filter' => [
        'name' => 'Acme',           // existing partial filter
        'company_id' => 'abc',       // existing exact filter
        'custom_fields' => [         // new: single key Spatie sees
            'amount' => ['gte' => 50000],
            'close_date' => ['lte' => '2026-06-30'],
        ],
    ],
    'sort' => '-amount',             // Spatie-native format
    'include' => 'company,contact',  // Spatie-native format
])
```

This way existing `AllowedFilter::partial('name')` and `AllowedFilter::exact('company_id')` continue to work. The new `AllowedFilter::custom('custom_fields', new CustomFieldFilter())` receives the entire nested object as its `$value`.

#### Query Execution

New invokable class `CustomFieldFilter` implements `Spatie\QueryBuilder\Filters\Filter`:

1. `__invoke(Builder $query, mixed $value, string $property)` receives `$value` as the full filter object: `["amount" => ["gte" => 50000], "stage" => ["in" => ["Proposal"]]]`
2. Resolves all field codes to custom_field_ids in one query (cached per request via `once()`)
3. Silently ignores field codes that don't resolve to active fields (defensive -- handles race condition between cached schema and deactivated fields)
4. For each field + operator pair:
   - Looks up value column via `CustomFieldValue::getValueColumn()` (handles SINGLE_CHOICE string vs integer keys correctly)
   - Maps operator string to SQL: `eq` → `=`, `gt` → `>`, `gte` → `>=`, `lt` → `<`, `lte` → `<=`, `contains` → `ILIKE %...%`, `in` → `whereIn`, `has_any` → `whereJsonContains`
   - Adds `$query->whereHas('customFieldValues', fn($q) => $q->where('custom_field_id', $id)->where($valueColumn, $sqlOp, $value))`
5. All conditions are AND'd (each whereHas is additive)
6. Maximum 10 filter conditions per request (reject with validation error if exceeded)

#### Sort by Custom Field

New invokable class `CustomFieldSort` implements `Spatie\QueryBuilder\Sorts\Sort`:

1. `__invoke(Builder $query, bool $descending, string $property)` where `$property` is the field code
2. Checks if property is a native column (name, created_at, updated_at) → uses direct `orderBy`
3. Otherwise resolves field code to custom_field_id and value column
4. Adds correlated subquery sort:
   ```php
   $query->orderBy(
       CustomFieldValue::query()
           ->select($valueColumn)
           ->whereColumn('entity_id', $model->getTable().'.id')
           ->where('entity_type', $model->getMorphClass())
           ->where('custom_field_id', $fieldId)
           ->limit(1),
       $descending ? 'desc' : 'asc'
   );
   ```
5. Falls back gracefully if field code doesn't resolve (no sort applied)

**Important:** Spatie QueryBuilder validates sort names against registered `allowedSorts()`. Unlike filters (where we nest everything under one `custom_fields` key), sorts must be registered individually per field code. Each custom field code becomes its own `AllowedSort::custom('field_code', new CustomFieldSort())`. This means List actions must dynamically build the allowed sorts array from the team's active custom fields (same cache as filter schema, 60s).

#### Integration Points

**BaseListTool changes:**
- `baseSchema()`: add optional `filter`, `sort`, `include` properties (dynamically built)
- `handle()`: construct full Request object with filter/sort/include, pass as `$request` to action
- New method `filterSchema()`: delegates to `CustomFieldFilterSchema` for dynamic schema generation

**List action changes (all 5):**
- Add `AllowedFilter::custom('custom_fields', new CustomFieldFilter())` to allowedFilters array
- Dynamically register `AllowedSort::custom($fieldCode, new CustomFieldSort())` for each active custom field code (built from same cached field list as filter schema)
- Native sorts (name, created_at, updated_at) remain as `AllowedSort::field()` entries
- No other changes -- allowedIncludes already defined

#### Performance

- Field code to ID resolution: single query, cached per request via `once()`
- PostgreSQL handles EXISTS subqueries efficiently (correlated subquery optimization)
- Max 10 filters prevents abuse
- New migration adds composite indexes on `custom_field_values`:
  - `(custom_field_id, float_value)` -- currency/number filtering
  - `(custom_field_id, date_value)` -- date filtering
  - `(custom_field_id, string_value)` -- select/text equality filtering (PostgreSQL handles text btree indexes natively, no prefix syntax needed)
  - Note: `contains` operator uses ILIKE which won't use btree indexes. Acceptable for v1 since the EXISTS subquery already narrows by custom_field_id. GIN trigram index can be added later if needed.

### 2. Relationship Includes in BaseListTool

#### Schema Changes

Add optional `include` array parameter to BaseListTool:

```json
{"include": ["company", "contact"]}
```

#### Available Includes (already defined in List actions)

| Entity | Includes |
|---|---|
| Companies | creator, people, opportunities |
| People | creator, company |
| Opportunities | creator, company, contact |
| Tasks | creator, assignees, companies, people, opportunities |
| Notes | creator, companies, people, opportunities |

#### Implementation

Handled in the BaseListTool.handle() Request construction (described in Section 1):
1. Extract `include` array from MCP request
2. Join as comma-separated string: `"company,contact"`
3. Set as `include` key in constructed Request
4. Spatie QueryBuilder reads it automatically via allowedIncludes()
5. JsonApiResource serializes loaded relationships in `included` section

Zero changes to List actions or Resource classes -- infrastructure already exists.

### 3. CRM Summary Aggregation Resource

#### Resource URI

`relaticle://summary/crm`

New MCP Resource (read-only) providing aggregate stats.

#### Response Shape

```json
{
  "companies": {"total": 142},
  "people": {"total": 387},
  "opportunities": {
    "total": 45,
    "by_stage": {
      "Prospecting": {"count": 12, "total_amount": 180000},
      "Qualified": {"count": 8, "total_amount": 340000},
      "Proposal": {"count": 5, "total_amount": 520000},
      "Closed Won": {"count": 15, "total_amount": 890000},
      "Closed Lost": {"count": 5, "total_amount": 150000}
    },
    "total_pipeline_value": 1040000,
    "total_won_value": 890000
  },
  "tasks": {
    "total": 203,
    "overdue": 12,
    "due_this_week": 8
  },
  "notes": {"total": 567}
}
```

#### Field Resolution Strategy

The aggregation resource needs specific custom field IDs for stage, amount, and due_date. These are resolved by **field code + entity type**, not by the `system_defined` flag (because `TaskField::DUE_DATE` has `isSystemDefined() = false`).

Resolution approach:
- Query `custom_fields` table where `code = 'stage' AND entity_type = 'opportunity' AND tenant_id = ?`
- Same for `amount` (opportunity) and `due_date` (task)
- Field codes come from the enum cases: `OpportunityField::Stage->value`, `OpportunityField::Amount->value`, `TaskField::DUE_DATE->value`
- If a field doesn't exist (team deleted it), the aggregation gracefully falls back to totals only (no by_stage breakdown, no amount sums)

**Morph type aliases:** This project uses `Relation::enforceMorphMap()`. The stored `entity_type` values are morph aliases (`'opportunity'`, `'task'`, `'people'`, `'company'`, `'note'`), NOT fully qualified class names. All SQL queries must use these aliases.

#### Implementation

New class `CrmSummaryResource` extends `Laravel\Mcp\Server\Resource`:

1. **Company/People/Notes**: Simple `COUNT(*)` queries scoped by team_id and soft deletes
2. **Opportunities**: JOIN to custom_field_values for stage (GROUP BY) and amount (SUM)
   - Resolves stage and amount custom field IDs from team's system-defined fields
   - Falls back gracefully if fields don't exist (returns totals only)
3. **Tasks**: JOIN for due_date custom field, compare with `CURRENT_DATE` and end of week
4. Cached 60 seconds (consistent with existing CrmOverviewPrompt)

#### Queries (5 total)

```sql
-- Companies count
SELECT COUNT(*) FROM companies WHERE team_id = ? AND deleted_at IS NULL;

-- People count
SELECT COUNT(*) FROM people WHERE team_id = ? AND deleted_at IS NULL;

-- Opportunities with stage/amount aggregation
-- Note: entity_type uses morph aliases from Relation::enforceMorphMap()
SELECT
  stage_cfv.string_value as stage,
  COUNT(*) as count,
  COALESCE(SUM(amount_cfv.float_value), 0) as total_amount
FROM opportunities o
LEFT JOIN custom_field_values stage_cfv
  ON stage_cfv.entity_id = o.id
  AND stage_cfv.entity_type = 'opportunity'
  AND stage_cfv.custom_field_id = ?
LEFT JOIN custom_field_values amount_cfv
  ON amount_cfv.entity_id = o.id
  AND amount_cfv.entity_type = 'opportunity'
  AND amount_cfv.custom_field_id = ?
WHERE o.team_id = ? AND o.deleted_at IS NULL
GROUP BY stage_cfv.string_value;

-- Tasks with overdue/due-this-week
-- Note: DUE_DATE is DATE_TIME type → stored in datetime_value column, cast to date for comparison
SELECT
  COUNT(*) as total,
  COUNT(*) FILTER (WHERE due_cfv.datetime_value::date < CURRENT_DATE) as overdue,
  COUNT(*) FILTER (WHERE due_cfv.datetime_value::date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '7 days')) as due_this_week
FROM tasks t
LEFT JOIN custom_field_values due_cfv
  ON due_cfv.entity_id = t.id
  AND due_cfv.entity_type = 'task'
  AND due_cfv.custom_field_id = ?
WHERE t.team_id = ? AND t.deleted_at IS NULL;

-- Notes count
SELECT COUNT(*) FROM notes WHERE team_id = ? AND deleted_at IS NULL;
```

All queries use aggregate functions -- no row-level iteration. Fast at any scale.

## New Files

| File | Type | Purpose |
|---|---|---|
| `app/Mcp/Filters/CustomFieldFilter.php` | Spatie Filter | Translates filter operators to whereHas queries |
| `app/Mcp/Filters/CustomFieldSort.php` | Spatie Sort | Translates sort by custom field to orderBy subquery |
| `app/Mcp/Schema/CustomFieldFilterSchema.php` | Schema builder | Generates dynamic JSON schema from team's custom fields |
| `app/Mcp/Resources/CrmSummaryResource.php` | MCP Resource | Aggregation endpoint |
| `database/migrations/..._add_custom_field_value_indexes.php` | Migration | Performance indexes |
| Tests for each new class | Pest tests | Coverage |

## Modified Files

| File | Change |
|---|---|
| `app/Mcp/Tools/BaseListTool.php` | Add filter, sort, include to schema; refactor handle() to build Request |
| `app/Mcp/RelaticleServer.php` | Register CrmSummaryResource |
| All 5 List actions | Add AllowedFilter::custom + AllowedSort::custom |

## Edge Cases

| Case | Behavior |
|---|---|
| Empty filter object `{}` | No-op -- filter key not added to Request |
| Unknown field code in filter | Silently ignored (field code doesn't resolve) |
| Deactivated field between schema cache and query | Silently ignored (same as above) |
| Encrypted custom field | Excluded from filterable schema entirely |
| FILE / long TEXT fields | Excluded from filterable schema |
| SINGLE_CHOICE with integer option keys | CustomFieldFilter uses `CustomFieldValue::getValueColumn()` to resolve correct column |
| MULTI_CHOICE `has_any` operator | Uses `whereJsonContains` on json_value column |
| Filter count > 10 | Returns validation error |
| Sort by non-existent field | Spatie rejects with 400 (field not in dynamically registered allowedSorts) |
| Include non-allowed relationship | Spatie QueryBuilder returns 400 error (existing behavior) |
| CRM Summary with missing system fields | Returns totals only, skips by_stage/amount breakdown |

## Testing Strategy

- Unit tests for CustomFieldFilter: each operator type, edge cases (empty values, unknown fields, encrypted fields, SINGLE_CHOICE column resolution)
- Unit tests for CustomFieldFilterSchema: schema generation per field type, exclusion of encrypted/FILE/TEXT fields
- Unit tests for CustomFieldSort: sort by each value column type, fallback for native columns
- Integration tests: end-to-end MCP tool calls with filter params → verify correct records returned
- Integration tests: include parameter → verify expanded relationships in response
- Integration test: CrmSummaryResource returns accurate aggregates with and without system fields
- Performance test: filter query with 10K+ records completes under 200ms

## Migration Path

1. Add value column indexes (migration)
2. Add CustomFieldFilter, CustomFieldSort, CustomFieldFilterSchema classes
3. Refactor BaseListTool.handle() to construct Request object
4. Modify List actions to register custom filters/sorts
5. Add CrmSummaryResource
6. Register in RelaticleServer
7. Update schema resources to document new filter capabilities
8. Update hero conversation to use real field codes from default custom fields

## Open Questions

1. Should the sort parameter support multiple fields? (e.g., sort by stage then by amount) -- recommend single field for v1
2. Should CrmSummaryResource be customizable (e.g., "summarize by X field")? -- recommend fixed schema for v1, parameterize later
3. Should we update the homepage hero conversation to use exact field codes from default custom fields? -- yes, after implementation
