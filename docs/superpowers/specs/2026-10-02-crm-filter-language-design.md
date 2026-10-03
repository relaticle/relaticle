# CRM filter language

Status: approved in brainstorming, 2026-10-02. Verified against `feat/custom-field-filter-contract-v1` at `26b9b3e4d` and production (read-only aggregates).

Extends `2026-10-01-custom-field-filter-contract-design.md`. That spec fixed custom field operators on one engine. This one turns the engine into one filter language for every list surface, and lifts the v1 non-goals for OR groups and native fields.

## Goal

One filter language answers a list question the same way on the REST API, MCP, and the in-app assistant (chat). It covers native fields, custom fields and relations, combines them with `$and`, `$or` and `$not`, and fails with an error that names the node to fix. "Open deals over 50k at ICP companies, owned by Ana or Bob" is one call on every surface.

## Non-goals

- A filter builder or saved views in the Filament UI. List tables keep the package filters; the existing parity test keeps shared operators returning the same records.
- Merging duplicate records (#885) and the import path that skips uniqueness (#884).
- Root-domain matching (`eu.acme.com` matching `acme.com`). It needs the public suffix list.
- Relative dates (`today`, `last_7_days`), sort syntax changes, full-text search changes.
- Filtering by record (lookup) and textarea custom fields, unchanged from v1.

## Decisions

### 1. Grammar: `$` marks a keyword, a bare key names something

A filter is a JSON object. Every key is one of:

| Key | Meaning |
|---|---|
| `$and`, `$or`, `$not` | logic |
| `$eq`, `$in`, `$contains`, ... | operator on the enclosing field or relation |
| a native field (`name`, `created_at`) | condition on a column |
| a relation (`company`, `assignees`) | condition on related records |
| `custom_fields` | conditions on the workspace's custom fields, keyed by field code |
| a sub-field (`domain`) | part of a custom field value |

Field codes are `alphaDash`, so no tenant-defined name can start with `$`. That is why the sigil is `$` and not `_`: `_eq` is a legal field code. There is no shorthand: `{"stage": "Won"}` is an error, not `$eq`.

### 2. Namespace: who owns a name decides where it lives

Names we define in code sit at the top level: native fields, relations, computed filters, keywords. Names tenants define sit under `custom_fields`. This mirrors the record JSON and every write payload, which already nest `custom_fields`.

A flat namespace is not possible: production holds 9 custom fields coded `name`, `id`, `company` or `opportunities`, and the custom field code rule does not reserve native names. Nesting means a future native column never collides with an existing custom field.

```json
{
  "name": {"$contains": "renewal"},
  "custom_fields": {"amount": {"$gt": 50000}, "stage": {"$in": ["Proposal", "Negotiation"]}},
  "$or": [
    {"custom_fields": {"close_date": {"$lte": "2026-12-31"}}},
    {"company": {"custom_fields": {"icp": {"$eq": true}}}}
  ]
}
```

### 3. Logic

- Keys in one object are ANDed. `$and` takes an array and exists for the case JSON cannot hold: two `$or` keys in one object.
- `$or` takes an array of nodes.
- `$not` takes one node and returns every record the node does not return, empty values included. It compiles as a set complement: `NOT EXISTS` over a derived table of the matching keys, which Postgres plans as a hash anti-join. (`NOT IN` over the same subquery degrades sharply past about 150k inner ids at the production `work_mem` of 4MB.) The inner query is built through the same registry, bounded to the acting workspace, with no alias on the model table (the architecture rules: `whereKey()` and `whereRelation()` qualify with the table name, which Postgres rejects under an alias). It never compiles as SQL `NOT (...)`, which drops rows where a nullable column is null. `$not_in`, `$has_none` and relation `$not_in` follow the same rule.
- An empty `$and` or `$or` array, an empty `$not` object, and an empty relation node are 422s. An empty top-level filter is no filter.

### 4. Relations

A relation node holds the same grammar, applied to the related entity and validated against that entity's own filters. `$` keys on a relation node act on the link itself.

| Entity | Record relations | Member relations (workspace users) |
|---|---|---|
| company | `people`, `opportunities` | `creator`, `accountOwner` |
| people | `company` | `creator` |
| opportunity | `company`, `contact` | `creator` |
| task | `companies`, `people`, `opportunities` | `creator`, `assignees` |
| note | `companies`, `people`, `opportunities` | `creator` |

Relation names match the JSON:API relationship and `include` names.

- Record relation operators: `$in`, `$not_in` (record ids), `$is_empty`, plus any nested node.
- Member relation operators: `$in`, `$not_in` (member ids), `$is_empty`. Member names and emails are not filterable.
- To-many means "at least one related record matches", and every condition in one relation node applies to the same related record. "None match" is `{"$not": {"people": {...}}}`. A link `$in` in the same node joins that same-record match: `{"people": {"$in": [id], "name": {...}}}` asks for one person who is both. `$not_in` and `$is_empty` are statements about the link as a whole and stay separate.
- Relations nest at most 2 hops: people, then company, then opportunities is allowed; a third relation is a 422.
- Every record relation subquery carries an explicit bound to the acting user's workspace. Related records are same-workspace by construction (`TenantFkValidator` on write), but queued chat jobs do not set the ambient workspace scope, so the filter never relies on it. A relation node exposes nothing a list endpoint does not.

This replaces `company_id`, `contact_id`, `people_id`, `opportunity_id`, `assignee_ids`, `notable_type` and `notable_id`.

### 5. Native fields and computed filters

| Entity | Text | Date-time | Enum | Computed |
|---|---|---|---|---|
| company | `name` | `created_at`, `updated_at` | `creation_source` | none |
| people | `name` | `created_at`, `updated_at` | `creation_source` | none |
| opportunity | `name` | `created_at`, `updated_at` | `creation_source` | `stale_days` |
| task | `title` | `created_at`, `updated_at` | `creation_source` | `assigned_to_me` |
| note | `title` | `created_at`, `updated_at` | `creation_source` | none |

A native field takes the operators of the custom field type it maps to: text to `text`, date-time to `date-time`, `creation_source` to `select` with `CreationSource` values. `CustomFieldFilterSchema::operatorsForType()` stays the single operator table. A bare date against a date-time field compares the UTC calendar date, as `created_after` and `created_before` did.

`stale_days` takes `$gte` (whole days without activity). `assigned_to_me` takes `$eq: true`.

### 6. Operators

| Type | Operators |
|---|---|
| Text | `$eq`, `$contains`, `$is_empty` |
| Number, currency, date, date-time | `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$is_empty` |
| Checkbox, toggle | `$eq`, `$is_empty` |
| Single choice, `creation_source` | `$eq`, `$in`, `$not_in`, `$is_empty` |
| Multi choice, tags | `$has_any`, `$has_none`, `$is_empty` |
| Email, phone, link | `$has_any`, `$has_none`, `$is_empty` |
| Email, link: `domain` sub-field | `$in`, `$not_in` |

Choice operands take an option label or ID, as in v1.

### 7. Value formats

Normalize on write where a lossless canonical form exists; compute at query time where it does not.

| Type | On write | When filtering |
|---|---|---|
| Phone | E.164, with an extension kept as `;ext=12` (`+14155550100;ext=12`) | operand normalized the same way, exact match |
| Email | stored as typed | `$has_any` case-insensitive; `domain` is the lowercased part after `@` |
| Link, URL variant (default) | scheme stripped, as the panel already does through `LinkFieldType::setValue()` | operand normalized the same way, `$has_any` case-insensitive; `domain` extracts the host: lowercase, no `www.`, no path |
| Link, domain variant | trimmed, lowercased; scheme, userinfo, port, path, query, fragment, leading `www.` and trailing dot removed | exact match |

Reasons, from the sources checked:

- Phones: the phone field type already promises E.164. The panel's `PhoneInputComponent` stores it, and every path validates `phone:AUTO`, which requires a country code. `CustomFieldInput::normalizeValue()` passes phones through as typed, so API, MCP, chat and import store `+1 415-555-0100` style values: 55 of 60 MCP writes, 11 of 18 chat writes, 29 import writes. E.164 alone drops extensions; libphonenumber's RFC 3966 form keeps them and parses back.
- Emails: RFC 5321 section 2.4 requires the local part to keep its case, and the app sends mail. 62 stored emails have an uppercase local part.
- Links: RFC 3986 section 6.2.2.1 makes scheme and host case-insensitive. Dropping `www.` is a product convention, not a standard. The `link` type also holds LinkedIn URLs, whose paths matter, so only a field set to the domain variant loses its path. The package already strips the scheme of every link in `LinkFieldType::setValue()`, documented as "Normalize a value before storage and comparison", but only the panel's `LinkComponent` and `UniqueCustomFieldValue` call it, so API, MCP, chat and import store schemes.
- Company `domains` declares `unique_per_entity_type`, but `UniqueCustomFieldValue` compares exact strings, so `https://acme.com` and `acme.com` both pass. 70% of stored values carry a scheme, and 144 hosts are shared by 426 companies through format differences alone.

Every place the app stores, matches or compares a custom field value uses the canonical form through one owner, `App\Support\CustomFields\CanonicalValue`: writes, API upsert matching, CSV import matching, the activity log's no-op check and chat's proposal diff. Matching also tries the raw spelling, so rows written before the backfill still match.

Uniqueness changes with it: `UniqueCustomFieldValue` normalizes each candidate with the field's normalizer. On save, it skips values the record already held before this change: a new or changed value must be unique; a record's existing values are grandfathered. Restoring a trashed record stays strict, because `takenUniqueCustomFieldValues()` exists to re-check exactly the values the record already holds. This unblocks the 291 companies whose identical duplicate domains already fail the panel form's uniqueness check, and keeps the 426 newly colliding ones saveable until #885 lets someone merge them.

### 8. Limits

| Limit | Value | Counted as |
|---|---|---|
| Conditions | 20 | each operator key in the tree, at any depth |
| Logic depth | 3 | `$and`, `$or`, `$not` keywords on the deepest path |
| Relation hops | 2 | relation nodes on the deepest path |
| List values | 100 | items in one `$in`, `$not_in`, `$has_any` or `$has_none` |

Each condition is one indexed EXISTS (`entity_id, custom_field_id` plus a per-type value index) or one `whereHas`. The numbers are confirmed with EXPLAIN on production-sized data during implementation and may only go down.

### 9. Transport

- `GET /v1/{entity}?filter[...]` with nested brackets replaces the v1 shape. Values arrive as strings and are coerced per field type. Free text is never split on commas. A text value of `true` or `false` stays text even though Spatie turns it into a boolean first.
- `POST /v1/{entity}/query` takes `filter`, `sort`, `include`, `per_page`, `cursor` and `page` in a JSON body and returns the same collection. It routes to the same controller `index` method and `IndexRequest`, because Spatie reads `filter` through `$request->input()`. A read-only token must be able to call it: the API group applies `EnsureTokenHasAbility` without parameters, which maps POST to `create`, so the middleware resolves a route to a controller's `index` method as `read` whatever the HTTP method.
- Production nginx caps the request line at 8 KB (default, no override). One maximal `$in` of record ids takes 5 to 6.4 KB, so the limits above only fit in a body.
- MCP and chat pass the tree as a tool argument.

### 10. Errors

Every filter error is a Laravel 422, keyed by the path of the node to fix. The messages below show the intent; the exact wording lives in `lang/en/validation.php`:

| Path | Message |
|---|---|
| `filter.custom_fields.stagee` | Unknown field stagee. Filterable fields: stage, amount, close_date. |
| `filter.custom_fields.amount.$contains` | amount is a currency field. Use $eq, $gt, $gte, $lt, $lte or $is_empty. |
| `filter.custom_fields.stage.eq` | Operators start with $. Use $eq. |
| `filter.created_after` | created_after was replaced. Use created_at with $gte. |
| `filter.company_id` | company_id was replaced. Use company with $in. |
| `filter.name` | name takes an operator object, for example filter[name][$contains]=Acme. |
| `filter.$or.1.company.people.company` | Relations nest at most 2 levels. |
| `filter` | A filter holds at most 20 conditions. This one has 23. |
| `filter.custom_fields.phone_number.$has_any.0` | phone_number needs a country code, for example +1 415 555 0100. |

A `filter` that is not an object (for example `?filter=stage`, which Spatie silently drops today) is a 422 too. A pre-pass validates every name, operator, limit and empty node before the query builder is built. Spatie checks names inside `allowedFilters()` and applies them in the same call, so the pre-pass runs first in each list action; every top-level name is then known and Spatie's 400 `InvalidFilterQuery` never fires for a filter. Sort and include errors keep Spatie's `InvalidQuery`. Messages live under `validation.filter.*` in `lang/en/validation.php`.

## Architecture

The engine composes spatie/laravel-query-builder 7 and Laravel's builder. Spatie keeps request parsing, the AllowedFilter registry, sorts, includes, fields and pagination; Laravel builds every query.

### Registry

Actions may expose only `execute()` (`ConventionsTest`), so the registry is its own class, keyed by the existing `CrmEntity` enum. It has two paths over one list of definitions:

```php
final readonly class EntityFilters
{
    public function __construct(private User $user) {}

    /** @return array<string, FilterDefinition> */
    public static function definitions(CrmEntity $entity): array
    {
        return match ($entity) {
            CrmEntity::Company => [
                'name' => FilterDefinition::text(),
                'created_at' => FilterDefinition::dateTime(),
                'updated_at' => FilterDefinition::dateTime(),
                'creation_source' => FilterDefinition::enum(CreationSource::class),
                'creator' => FilterDefinition::members(),
                'accountOwner' => FilterDefinition::members(),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
            ],
            // the other four entities follow the tables in decisions 4 and 5
        };
    }

    /** @return array<int, AllowedFilter> */
    public function for(CrmEntity $entity): array
    {
        // definitions -> NativeFilter / RelationFilter / computed filters,
        // plus CustomFieldFilter and LogicFilter, all bound to $this->user
    }
}
```

- `definitions()` needs no user and no database. Scribe documents from it, because docs generation runs with no workspace. Custom fields appear there as the `filter[custom_fields][{code}][{operator}]` template Scribe uses today.
- `for()` is the apply path. Each list action builds it with the acting `$user`, runs `FilterTree` over the request's `filter`, then passes `...$filters->for(CrmEntity::Company)` to `allowedFilters()`. The user is passed, never read from `auth()`: chat list tools run in queued jobs. `CustomFieldFilter` takes the same user instead of calling `auth()->user()` as it does today.
- `stale_days` and `assigned_to_me` are definitions with a computed kind; their closures receive the user from `for()`.
- A relation resolves the related entity's filters only when applied, so company, people, company cannot recurse while the registry is built.

### Components

All in `app/Support/Filters/`, moved from `app/Mcp/Filters/` because REST, MCP and chat share them:

| Class | Role |
|---|---|
| `TreeAllowedFilter` | today's `CustomFieldAllowedFilter`, generalized: an AllowedFilter that skips Spatie's comma splitting and empty pruning |
| `FilterTree` | the pre-pass: walks the tree, checks names against the registry, operators against types, limits and empty nodes, and throws one `ValidationException` with path keys |
| `NativeFilter` | column conditions; operators from the mapped custom field type |
| `RelationFilter` | `whereHas` over the related entity's registry; relation `$in`/`$not_in`/`$is_empty`; `whereBelongsTo` for to-one ids |
| `LogicFilter` | `$and`, `$or`, `$not`; applies child nodes through `AllowedFilter::applyTo()`, the call Spatie's own `FiltersGroup` uses |
| `CustomFieldFilter` | today's engine, with `$` operators, the `domain` sub-field, case-insensitive email and link matching, and phone operand normalization |
| `CustomFieldSort` | moved, unchanged |

`App\Mcp\Schema\CustomFieldFilterSchema` stays the owner of operators per type, as the architecture rules name it.

`ConventionsTest` fails a public method outside a model, enum or `Scope` that takes a query builder, unless the method implements an interface or overrides a parent (`hasPrototype()`). So builder-taking code lives only in Spatie `Filter::__invoke()` implementations, `applyTo()` overrides and private helpers. `FilterTree` takes arrays, never a builder.

### Published vocabulary

Each filter class describes itself (name, kind, operators, options, related entity). One builder turns an action's registry into the vocabulary for a workspace. `get-crm-schema` and the schema resources publish it for MCP, chat inlines it in the list tool description, and Scribe documents it. `SurfaceParityTest` asserts all three equal the registry.

### custom-fields package (3.12)

The package work is merged on `3.x` (PR #244). The app requires it as `3.x-dev as 3.12.0` until both PRs are finalized end to end; only then is `v3.12.0` tagged and the constraint switched to `^3.12`.

1. The normalizer is the package's existing hook, `BaseFieldType::setValue(string): string`. A new `BaseFieldType::normalize(string $value, CustomField $field): string` defaults to `setValue($value)` so a setting can choose the form; existing third-party field types keep working. `SafeValueConverter::toDbSafe()` gains an optional `?CustomField` and runs `normalize()` on every string item. Every write path already calls `toDbSafe()`: `CustomFieldValue::setValue()` (panel, API, MCP, chat, actions), `ExecuteImportJob` and `BulkCustomFieldValueWriter`. `LinkComponent` and `UniqueCustomFieldValue` switch from `setValue()` to `normalize()`.
2. `PhoneFieldType::setValue()`: E.164 plus RFC 3966 extension, through `CountryPhoneService`. Input it cannot parse comes back unchanged; validation stays the gate. `CountryPhoneService::formatToE164()` (the panel's input) and `parseE164()` (the panel's display) keep the extension too.
3. Link setting `link_variant`: `url` (default) or `domain`. `LinkFieldType::normalize()` applies the domain form from decision 7 when the field is set to `domain`, and `setValue()` otherwise.
4. `UniqueCustomFieldValue` normalizes candidates and, in the save validation path only, grandfathers values the record already held. The restore check stays strict.

## Surfaces

- **REST.** GET and POST as in decision 9. Scribe's `GetFromSpatieQueryBuilder` reads `EntityFilters::definitions()` instead of parsing action source, works with no custom fields present, and documents the POST endpoints with body examples.
- **MCP.** `BaseListTool` takes `filter`, `sort`, `include`, `per_page`, `page`. `search`, `created_after`, `created_before`, `creation_source` and every `additionalFilters()` param go: each is a filter node, and `SearchTool` keeps full-text search. The `filter` description states the grammar; the vocabulary comes from `get-crm-schema`.
- **Chat.** `BaseReadListTool` takes `filter` in place of `custom_fields` and the flat params; `lookup` and `sort` stay. The inlined description comes from the vocabulary builder. Prompt text that names a removed param moves to the new syntax. No tool is added, so `toolLabels` is unchanged.
- **Docs.** The MCP guide and the "find anything with search and filters" help page move to the new syntax. A bare `: ` inside unquoted YAML front matter there 500s every docs page.

## Rollout

1. Tag custom-fields `v3.12.0` after the end-to-end walk; switch the constraint from `3.x-dev as 3.12.0` to `^3.12`.
2. Migration: set `link_variant` to `domain` on every company `domains` field (query builder, chunked with `eachById`). `CompanyField::DOMAINS` declares it for new workspaces.
3. Command `custom-fields:normalize-values {--force}`: reports by default, writes on `--force`, idempotent, chunked, query builder only.
   - Every phone and link value is re-run through its field type's `normalize()`; a value already in canonical form is left alone, so a second run changes nothing.
   - Phones: converts the 111 international values stored with formatting. Report the 8,505 national numbers by workspace and leave them as they are: 7,839 from the old onboarding seed (6 distinct values), 665 typed before the E.164 picker, 1 imported. No country is guessed.
   - Links: strips schemes stored by API, MCP, chat and import. Domain-variant links also lose `www.` and paths, collapse duplicates within a record, and cross-record collisions are reported by workspace.
4. Migration queues it: `Artisan::queue('custom-fields:normalize-values', ['--force' => true])->onQueue('imports')->afterCommit()`, after the settings migration. Self-hosted installs run it the same way.
5. Release note: the v1 filter shape is replaced; old shapes return 422s naming the replacement. Tell the paying API customer before the release.

## Testing

Feature tests through real entry points (REST GET and POST, MCP list tools, chat list tools), extending the existing files: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`, `tests/Feature/Api/V1/OpportunitiesApiTest.php`, `tests/Feature/Chat/ListToolFilterTest.php`, `tests/Feature/CRM/SurfaceParityTest.php`, `ApiDocumentationGenerationTest`.

- Grammar: `$` operators; bare operators, shorthand and v1 params return the 422s in decision 10.
- Logic: `$and` of two `$or`; `$not` includes empty native, custom and relation values (an opportunity with no contact is returned by `$not` of `contact.$in`); empty arrays and nodes 422; a string `filter` 422.
- Relations: one and two hops; a third hop 422; to-many matches one record satisfying every condition (a CTO in Berlin, not a CTO and a Berliner); relation `$in`, `$not_in`, `$is_empty`; member relations by id.
- Limits: 21 conditions, depth 4 and 101 values each 422 with the path.
- GET coercion: numbers, booleans, comma lists for choices and ids; free text with commas and a text value `true` stay whole.
- POST `/query`: a read-only token succeeds; the response equals GET for the same filter.
- Value formats: phone E.164 from panel, API, MCP, chat and import, extension kept, operand normalized; email `$has_any` across case; email and link `domain`; domain variant normalization; uniqueness grandfathers stored values on save, rejects a new duplicate in any format, and still blocks restoring a trashed record whose value is taken.
- Backfill: report mode writes nothing; `--force` converts, reports national phones and collisions, and a second run changes nothing; the migration names an existing command (`ConventionsTest`).
- Parity: MCP vocabulary, chat description and Scribe parameters equal the registry for every entity; `definitions()` names equal the AllowedFilter names `for()` builds; Scribe generation passes with no custom fields.
- Architecture: `ConventionsTest` stays green (one public method per action, no public builder parameter without a prototype).

Chat changes are verified on the production-shaped stack (Horizon, `QUEUE_CONNECTION=redis`, Reverb) in a real browser: a list question with an OR and a relation, then a phone lookup.

## Risks

- Query cost: each condition is an EXISTS or `whereHas`; 20 of them across 2 hops is the worst case. EXPLAIN on production-sized data sets the final limits.
- The chat prompt cache misses once when the list tool schemas change.
- 8,505 national phone numbers stay unconverted and do not match an E.164 operand until someone edits them. 7,839 of them are sample data.
- Duplicate companies stay duplicates until #885 ships.
- The engine relies on Spatie's public `AllowedFilter::applyTo()`, `isForFilter()` and `InvalidFilterQuery`. A Spatie major release needs a check of all three.
