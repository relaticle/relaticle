# A domain field type for company domains

**Date:** 2026-10-06
**Issues:** #933 (this design), #929 (closes without code, see the last section)
**Repos:** `relaticle/custom-fields` 3.14, then `relaticle/relaticle`
**Status:** design, awaiting review

## Goal

Company `domains` gets its own field type, `domain`. Today it is a `link` field with a hidden
setting, `link_variant: domain`, that changes the stored form. One type name covers two shapes,
and every domain-only branch checks the type and the setting together.

After this change the type alone says what the field stores. The setting is gone from both
repos.

## What a user or an agent observes

- `domains` reports `type: domain` on the REST API, the MCP schema and the chat tools. It
  reported `type: link` before. This goes in the release note.
- A `domains` filter accepts `$has_any` and `$has_none`. The `domain` sub-field
  (`domains.domain.$in`) returns the unsupported-operator error.
- Writing a value behaves as it does today. Any URL is accepted and stored as its lowercase
  host: `https://www.Acme.com/pricing?x=1` becomes `acme.com`.
- A filter or upsert operand is reduced the same way, so `$has_any: ["https://acme.com/about"]`
  finds `acme.com`.
- The custom-field settings form does not offer Domain as a type. Chat and MCP cannot create
  a domain field.

## Decisions

| # | Decision | Rejected |
|---|---|---|
| 1 | Domain is system-defined in Relaticle. No surface creates one | user-creatable on any entity |
| 2 | The package adds a `systemOnly()` flag on a type. `DomainFieldType` lives in Relaticle | a domain type inside the package, a config list in `FieldTypeConfigurator` |
| 3 | The host normalizer moves to Relaticle. Package 3.14 deletes `link_variant` | a deprecated setting kept until 4.0, a public helper in the package |
| 4 | Domain accepts any URL and stores the host. A path is not an error | a host-only validation rule |
| 5 | A domain field filters with `$has_any` and `$has_none` only | keeping the `domain` sub-field as a second spelling |

Decision 4 overrides the issue's proposal of a host-only rule. Decision 3 removes an internal
setting in a minor release. The package docs call it internal, it shipped on 2026-10-02, and
only Relaticle sets it.

## `relaticle/custom-fields` 3.14

Branch off `3.x`.

- `FieldSchema::systemOnly(bool $systemOnly = true)` sets a new `FieldTypeData::$systemOnly`
  property, default `false`.
- `TypeField` leaves system-only types out of `getAllFormattedOptions()` and
  `getSearchResults()`. The type of the record being edited stays in, because the edit form
  shows it in a disabled select.
- `FieldForm` picks its default type (`FieldForm.php:286`) from the same filtered list.
- `LinkFieldType::normalize()` is deleted, so the type falls back to
  `BaseFieldType::normalize()`, which calls `setValue()`. `equivalentValues()` keeps its
  scheme spellings. The `WHITESPACE` constant goes if nothing else reads it.
- `docs/content/2.essentials/6.data-model.md` loses the domain-variant row and sentence, and
  gains a short note on `systemOnly()`.
- Tests: the domain-variant cases in `CustomFieldValueNormalizationTest` and
  `UniqueCustomFieldValueTest` are removed. New cases cover the picker filter and the
  edit-form exception.
- The same changes are forward-ported to `feat/4.0`, which also carries `link_variant`.

## Relaticle

### The type

`App\Filament\CustomFields\DomainFieldType extends BaseFieldType`, beside the three types
already registered from that folder.

- `configure()`: `FieldSchema::multiChoice()`, key `domain`, a translated label, multi-value,
  unique-capable, arbitrary values, no user options, `systemOnly()`. It uses the package's
  `LinkComponent`, `LinkColumn` and `LinkEntry`. `LinkComponent` already calls the type's
  `normalize()` on dehydrate.
- `defaultItemValidationRules()` carries the same two rules the link type has.
- The import wizard infers a column's type from those rules, one type per rule
  (`DataTypeInferencer`). Two types now share the URL rule, so the inferencer keeps `link` as
  the inferred type and suggests the fields of every type that shares the rule.
- The content of the values decides which fields are offered. A type that would drop the
  path or the query string of most sample values is not suggested for that column. A column
  of LinkedIn profile URLs maps to `linkedin` and never to `domains`, and a column of
  homepages maps to `domains`. When no fitting field is free the column stays unmapped.
  Fields that fit keep the order they were created in. A header match still wins before any
  of this runs.
- `setValue()` holds the host logic that `LinkFieldType::normalize()` has at tag `v3.13.1`,
  moved as it is. The base `normalize()` calls it, so the type needs no field setting.
- `equivalentValues()` returns the host plus the link type's spellings of the typed value
  (`https://acme.com`, `http://acme.com`, and `www.acme.com` when the input carries `www.`),
  by delegating to `LinkFieldType::equivalentValues()`. A domain stored before normalization
  keeps matching in filters, upsert and the unique rule when the same spelling is typed.
  `LegacyCompanyDomains` and four existing tests pin that. A bare `acme.com` does not match a
  legacy `www.acme.com`, as in 3.13. The `custom-fields:normalize-values` pass removes those.
- Registered in `AppServiceProvider` with `'domain' => DomainFieldType::class`.
- `tests/Arch/ArchTest.php`: `App\Filament` is already exempt from `avoid inheritance`. The
  class joins the ignore list of the custom-fields package models check, because the inherited
  `equivalentValues()` signature names the package's `CustomField`.

`CustomFieldFilter::DOMAIN_OF` keeps its link entry, a SQL mirror of the host rule for link
fields such as `linkedin`. It gets no domain entry, because decision 5 removes the sub-field.

### Every branch on the link type

| File | Change |
|---|---|
| `app/Enums/CustomFieldType.php` | `DOMAIN = 'domain'`, with arms in `inputFormat()`, `icon()`, `example()`, `filterMatching()` and `filterExample()` |
| `app/Enums/CustomFields/CompanyField.php` | `DOMAINS` returns `CustomFieldType::DOMAIN`. `additionalSettings()` is deleted |
| `app/Enums/CustomFields/CustomFieldTrait.php`, `app/Listeners/CreateWorkspaceCustomFields.php` | `additionalSettings()` and its one call go, since no field sets any |
| `app/Queries/CustomFieldFilterSchema.php` | domain joins the `PHONE` arm: `$has_any` and `$has_none` |
| `app/Queries/Filters/CustomFieldFilter.php` | domain joins the lowercase compare in `containsAny()` and the type list in `spellings()`. The `link_variant` check at line 362 becomes a type check |
| `app/Console/Commands/NormalizeCustomFieldValuesCommand.php` | `whereIn` gains domain, `$isDomain` is a type check, and the description names domain |
| `app/Support/CustomFields/CustomFieldInput.php` | domain joins the pass-through arm of the `match` |
| `packages/Chat/src/Services/Tools/ProposalFieldSchemaDescriber.php` | `kindFor()` returns `link` for domain |
| `packages/Chat/src/Services/Tools/CustomFieldsDisplayFormatter.php` | `storedValues()`, `displayType()` and the line 312 type list treat domain like link |
| `app/Actions/CustomFields/CreateCustomField.php` | no change. `ALLOWED_TYPES` stays without `domain` |

`packages/SystemAdmin` has no `CustomFieldType` reference, so the manual sweep the issue asks
for is clear. The implementation re-greps it before the PR.

### The migration

One migration, `up()` only, in the query builder:

- Scope: `custom_fields` rows where `entity_type = 'company'`, `code = 'domains'` and
  `type = 'link'`.
- Change: `type = 'domain'`, and `link_variant` removed from `settings.additional`.
- `custom_field_values` is not touched. The stored hosts keep their shape.

A second run matches no row. It is proven by a rehearsal on anonymized production data, as
`core.md` describes. The diff must show every `company.domains` row changed, every other
`link` row untouched, and `custom_field_values` row counts equal.

## Tests

Existing files are extended. No new file unless a scope has none.

- `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`: the two `link_variant` fixtures
  become domain-type fields. New cases: `$has_any` with a full URL finds the host, and
  `domains.domain.$in` returns the unsupported-operator error.
- `tests/Feature/Api/V1/CompaniesApiTest.php` and `CompaniesUpsertApiTest.php`: the existing
  write and match cases pass unchanged. The custom-fields endpoint reports `type: domain`.
- `tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php`: the shared-domain report
  runs for a domain-type field.
- `tests/Feature/ActivityLog/CustomFieldActivityTest.php`: its fixture becomes a domain-type field.
- `tests/Feature/CRM/SurfaceParityTest.php` stays green with the new enum case.
- New cases in the files that cover them today: the settings form does not offer Domain, and
  the chat and MCP create-field tools reject `type: domain`.
- The company form and the custom-field settings page get a browser walk in Chromium and in
  WebKit: add a domain, paste a URL with a path, edit the system `domains` field.

## Order of work

1. Package branch off `3.x` with the flag, the picker filter and the removal.
2. Relaticle branch requires that package branch. It carries the type, the branch list above,
   the migration and the tests, and gets the full walk and the migration rehearsal.
3. Tag `v3.14.0` with `gh release create`, then bump Relaticle to `^3.14` and merge.
4. Forward-port the package changes to `feat/4.0`.
5. Release note: `domains` now reports `type: domain`.

The Relaticle PR cannot merge on 3.13, because `link_variant` and `systemOnly()` change in the
same package release.

## Out of scope

Root domain and subdomain matching, logos and enrichment. A user-creatable domain field. A
host-only validation rule.

## #929

The trailing-slash defect is fixed in `relaticle/custom-fields` 3.13.1, which main requires.

- `LinkFieldType::setValue()` drops trailing slashes and lower-cases the host. The package test
  pins `http://acme.com//` to `http://acme.com`.
- Filter operands and the unique rule compare through `equivalentValues()`.
- The lock bump to 3.13.1 and the migration that queues `custom-fields:normalize-values`
  reached main together in #906, so the stored-value pass runs under the fixed code.

It closes with a comment citing those three facts, after one read-only production query
confirms no `link` value ends in `/`. If some do, a migration re-queues the command in the
shape of `2026_10_03_000100_queue_custom_field_value_normalization`.
