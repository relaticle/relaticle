# Custom Field Filter Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Custom-field filters return the same correct rows on the REST API, MCP, chat and the Filament list tables, accept choice labels or IDs, and gain `not_in`, `has_none` and `is_empty`.

**Architecture:** Two engines stay. The vendor package `relaticle/custom-fields` owns the UI table filters and gets an any-of fix. The app engine `App\Mcp\Filters\CustomFieldFilter` serves API, MCP and chat, and absorbs label resolution from chat's `CustomFieldsFilterTranslator`, which is deleted. A contract test in `tests/Feature/CRM/SurfaceParityTest.php` proves both engines return the same records.

**Tech Stack:** Laravel 12, PHP 8.5, PostgreSQL, spatie/laravel-query-builder, laravel/mcp, Filament 4, Pest 5, relaticle/custom-fields 3.x.

**Spec:** `docs/superpowers/specs/2026-10-01-custom-field-filter-contract-design.md`

## Global Constraints

- Worktree: `/Users/manuk/worktrees/relaticle-custom-field-filter-contract`, branch `feat/custom-field-filter-contract`. Never work in the `barcelona` workspace.
- Test database: `DB_DATABASE=relaticle_filter_contract_testing` prefixed to every `php artisan test` run. Create it once: `psql -h 127.0.0.1 -U root -d postgres -c "create database relaticle_filter_contract_testing"`.
- PostgreSQL only. No migrations in this plan.
- Every parameter, property and return is typed; type coverage stays at 100%.
- No new PHPStan ignores. No em-dash (U+2014) anywhere.
- No comments in tests. Code comments only for a non-obvious why, two lines max. Docblocks carry types only.
- `CustomFieldFilterSchema::MAX_LIST_VALUES = 100`; `CustomFieldFilter::MAX_CONDITIONS = 10` (unchanged).
- Choice values are stored as option IDs. Labels are input only. Tags-input and lookup-backed fields store raw strings and skip label resolution.
- `not_in` and `has_none` match records where the field is empty.
- `is_empty: true` matches no value row, a null value, `''` in a string or text column, or a null or `[]` json array. `is_empty: false` matches the rest.
- Error messages come from `lang/en/validation.php` keys under `custom_field`.
- Per task gates: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse --memory-limit=2G`, the task's targeted tests.
- Commit per task with a conventional, lowercase subject under 72 characters. No AI attribution. Do not push, open a PR, merge, or tag without the user's explicit go.

## Review Focus

1. A tags-input field with free-text values filtered by `has_any`: matches the raw strings, never raises "not one of the options". Pinned in Task 3.
2. A choice list mixing a label and an option ID: both resolve. Pinned in Task 3.
3. REST `is_empty=0` and `is_empty=false`: treated as `false`, not as a truthy string. Pinned in Task 4.
4. A label with different case and surrounding spaces (`"  closed won "`): resolves to the option. Pinned in Task 3.
5. An empty list (`not_in: []`): rejected, never "match everything". Pinned in Task 4.

---

### Task 1: Package any-of fix for multi-value table filters

**Files:**
- Modify: `src/Filament/Integration/Components/Tables/Filters/SelectFilter.php` (the `$filter->query(...)` call)
- Modify: `src/Filament/Integration/Components/Tables/Filters/TagsFilter.php` (the `$filter->query(...)` call)
- Test: `tests/Feature/Integration/Resources/Pages/ListRecordsTest.php`

All paths are inside the package repo. The local clone is `/Users/manuk/conductor/workspaces/relaticle/custom-fields-work` (remote `origin` is `relaticle/custom-fields`, default branch `3.x`).

**Interfaces:**
- Consumes: nothing.
- Produces: a released package version (for example `v3.10.1`) that Task 7 pins.

- [ ] **Step 1: Create a package worktree**

```bash
git -C /Users/manuk/conductor/workspaces/relaticle/custom-fields-work fetch origin
git -C /Users/manuk/conductor/workspaces/relaticle/custom-fields-work worktree add -b fix/multi-value-table-filters-match-any /Users/manuk/worktrees/custom-fields-filter-any origin/3.x
cd /Users/manuk/worktrees/custom-fields-filter-any && composer install --no-interaction
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/Integration/Resources/Pages/ListRecordsTest.php`. Add `use Relaticle\CustomFields\Models\CustomFieldSection;` if it is not already imported (it is in this file today).

```php
describe('Custom field table filters', function (): void {
    beforeEach(function (): void {
        $this->section = CustomFieldSection::factory()
            ->forEntityType(Post::class)
            ->create(['active' => true]);
    });

    it('matches a post holding any one of the picked multi-select options', function (): void {
        $field = CustomField::factory()
            ->ofType('multi-select')
            ->withOptions(['Hot', 'Warm', 'Cold'])
            ->create([
                'custom_field_section_id' => $this->section->getKey(),
                'entity_type' => Post::class,
                'code' => 'temperature',
            ]);
        $optionId = fn (string $name): int|string => $field->options()->where('name', $name)->value('id');

        $hot = Post::factory()->create();
        $warm = Post::factory()->create();
        $cold = Post::factory()->create();
        $hot->saveCustomFieldValue($field, [$optionId('Hot')]);
        $warm->saveCustomFieldValue($field, [$optionId('Warm')]);
        $cold->saveCustomFieldValue($field, [$optionId('Cold')]);

        livewire(ListPosts::class)
            ->filterTable('custom_fields.temperature', [$optionId('Hot'), $optionId('Warm')])
            ->assertCanSeeTableRecords([$hot, $warm])
            ->assertCanNotSeeTableRecords([$cold]);
    });

    it('matches a post holding any one of the picked tags', function (): void {
        $field = CustomField::factory()
            ->ofType('tags-input')
            ->create([
                'custom_field_section_id' => $this->section->getKey(),
                'entity_type' => Post::class,
                'code' => 'labels',
            ]);

        $urgent = Post::factory()->create();
        $vip = Post::factory()->create();
        $archived = Post::factory()->create();
        $urgent->saveCustomFieldValue($field, ['urgent']);
        $vip->saveCustomFieldValue($field, ['vip']);
        $archived->saveCustomFieldValue($field, ['archived']);

        livewire(ListPosts::class)
            ->filterTable('custom_fields.labels', ['urgent', 'vip'])
            ->assertCanSeeTableRecords([$urgent, $vip])
            ->assertCanNotSeeTableRecords([$archived]);
    });
});
```

If `ofType('multi-select')` does not resolve, read `src/FieldTypeSystem/Definitions/MultiSelectFieldType.php` for its `->key(...)` and use that key.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter="Custom field table filters"`
Expected: both FAIL. `assertCanSeeTableRecords` reports the warm and vip posts missing, because the filters require every picked value.

- [ ] **Step 4: Fix `SelectFilter`**

Replace the `$filter->query(...)` call in `SelectFilter::make()` with:

```php
        $filter->query(
            fn (array $data, Builder $query): Builder => $query->when(
                ! empty($data['values']),
                fn (Builder $query): Builder => $query->whereHas('customFieldValues', function (Builder $query) use ($customField, $data): void {
                    $query->where('custom_field_id', $customField->id);

                    if ($customField->getValueColumn() !== 'json_value') {
                        $query->whereIn($customField->getValueColumn(), $data['values']);

                        return;
                    }

                    $query->where(function (Builder $anyOption) use ($data): void {
                        foreach ($data['values'] as $value) {
                            $anyOption->orWhereJsonContains('json_value', [$value]);
                        }
                    });
                }),
            )
        );
```

- [ ] **Step 5: Fix `TagsFilter`**

Replace the `foreach ($data['values'] as $tag)` block inside its `whereHas` with:

```php
                    $query->where(function (Builder $anyTag) use ($data): void {
                        foreach ($data['values'] as $tag) {
                            $anyTag->orWhereJsonContains('json_value', [$tag]);
                        }
                    });
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter="Custom field table filters"`
Expected: 2 passed.

- [ ] **Step 7: Run the package gates**

Run: `composer test:lint && composer test:types && composer test:type-coverage && vendor/bin/pest --parallel`
Expected: all green. Fix any finding at its source.

- [ ] **Step 8: Commit**

```bash
git add src/Filament/Integration/Components/Tables/Filters/SelectFilter.php src/Filament/Integration/Components/Tables/Filters/TagsFilter.php tests/Feature/Integration/Resources/Pages/ListRecordsTest.php
git commit -m "fix(filters): match any picked value in multi-value table filters"
```

- [ ] **Step 9: Hand off for release**

Ask the user to approve pushing the branch and opening a PR against `3.x`. Draft the PR body for approval first. It must state the behavior change: multi-select, checkbox-list and tags table filters now match records holding any picked value, where they used to require all. The release (tag via `gh release create vX.Y.Z --latest`) happens only on the user's explicit instruction. Record the released version for Task 7.

---

### Task 2: One filterable-field predicate, one translation rule, one error wording

**Files:**
- Modify: `app/Mcp/Schema/CustomFieldFilterSchema.php` (`resolveFilterableFields()`, new `isFilterable()`)
- Modify: `app/Mcp/Filters/CustomFieldFilter.php` (`__invoke()`, replace `resolveFields()` with `filterableFields()`)
- Modify: `app/Support/CustomFields/CustomFieldOptionMap.php` (new `translates()`)
- Modify: `app/Support/CustomFields/CustomFieldInput.php` (`skipsOptionTranslation()` removed, callers use `translates()`)
- Modify: `lang/en/validation.php` (`custom_field` array)
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`

**Interfaces:**
- Consumes: `App\Support\CustomFields\WorkspaceCustomFields::forEntity(Workspace $workspace, string $entityType): Collection<int, CustomField>` (scoped singleton, eager-loads `options`, cleared on field and option save/delete).
- Produces:
  - `CustomFieldFilterSchema::isFilterable(CustomField $field): bool` (static)
  - `CustomFieldOptionMap::translates(CustomField $field): bool`
  - `CustomFieldFilter::filterableFields(): Collection<string, CustomField>` (private, keyed by code)
  - lang keys `validation.custom_field.unknown_filter_field` (`:field`, `:entity`, `:available`), `validation.custom_field.unsupported_filter_operator` (`:operator`, `:field`, `:supported`), `validation.custom_field.too_many_values` (`:field`, `:max`)

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`, add imports `use App\Models\CustomFieldSection;`, `use App\Models\Workspace;` and `use Relaticle\CustomFields\Data\CustomFieldSettingsData;`, then add this helper below `beforeEach`:

```php
function filterTestField(Workspace $workspace, string $entityType, string $code, string $type, ?CustomFieldSettingsData $settings = null): CustomField
{
    $section = CustomFieldSection::create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => $entityType,
        'name' => "{$code} section",
        'code' => "{$code}_section",
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);

    return CustomField::create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => $entityType,
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'type' => $type,
        'sort_order' => 99,
        'active' => true,
        'validation_rules' => [],
        'settings' => $settings ?? new CustomFieldSettingsData,
    ]);
}
```

If `App\Models\CustomFieldSection` does not exist, use the class that `tests/Feature/Api/V1/OpportunitiesApiTest.php` imports for `CustomFieldSection`.

Add the test:

```php
it('rejects an encrypted custom field as an unknown filter code', function (): void {
    filterTestField($this->workspace, 'opportunity', 'secret_code', 'text', new CustomFieldSettingsData(encrypted: true));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => ['secret_code' => ['eq' => 'x']],
        ])
        ->assertHasErrors(['"secret_code" is not a filterable custom field on opportunity']);
});
```

Update the four existing assertions that pin the old engine wording:

```php
})->throws(ValidationException::class, '"nonexistent_field" is not a filterable custom field on opportunity.');
```

```php
})->throws(ValidationException::class, 'Operator "approximately" is not supported for "amount".');
```

```php
        ->assertHasErrors(['Operator "contains" is not supported for "amount".']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`
Expected: the encrypted test FAILS (no error raised) and the three wording tests FAIL on message mismatch.

- [ ] **Step 3: Add the lang keys**

In `lang/en/validation.php`, inside `'custom_field' => [`, after `'ambiguous_option'`:

```php
        'unknown_filter_field' => '":field" is not a filterable custom field on :entity. Available: :available.',
        'unsupported_filter_operator' => 'Operator ":operator" is not supported for ":field". Supported: :supported.',
        'too_many_values' => ':field: pass at most :max values.',
```

- [ ] **Step 4: Expose the schema predicate**

In `CustomFieldFilterSchema`, add:

```php
    public static function isFilterable(CustomField $field): bool
    {
        return $field->active
            && ! in_array($field->type, self::EXCLUDED_TYPES, true)
            && ! $field->settings->encrypted
            && self::operatorsForType($field->type) !== [];
    }
```

and change the filter in `resolveFilterableFields()` to:

```php
            ->filter(self::isFilterable(...))
```

- [ ] **Step 5: Move the translation rule into the option map**

In `CustomFieldOptionMap`, add (import `App\Enums\CustomFieldType`, `App\Models\CustomField`, and the same `CustomFieldsType` facade `CustomFieldInput` imports today):

```php
    public function translates(CustomField $field): bool
    {
        if (CustomFieldType::tryFrom($field->type)?->isChoice() !== true) {
            return false;
        }

        $typeData = CustomFieldsType::getFieldType($field->type);

        return $typeData !== null && ! $typeData->acceptsArbitraryValues && $field->lookup_type === null;
    }
```

In `CustomFieldInput`, delete `skipsOptionTranslation()` and replace each `if ($this->skipsOptionTranslation($field))` with `if (! $this->optionMap->translates($field))`. Remove imports that become unused.

- [ ] **Step 6: Resolve fields through the shared predicate**

In `CustomFieldFilter`, replace `resolveFields()` with:

```php
    /**
     * @return Collection<string, CustomField>
     */
    private function filterableFields(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return resolve(WorkspaceCustomFields::class)
            ->forEntity($user->currentWorkspace, $this->entityType)
            ->filter(CustomFieldFilterSchema::isFilterable(...))
            ->keyBy('code');
    }
```

In `__invoke()`, replace the field resolution and the two error calls:

```php
        $fields = $this->filterableFields();

        $unknownFieldCodes = array_diff($fieldCodes, $fields->keys()->all());

        if ($unknownFieldCodes !== []) {
            $this->invalid(__('validation.custom_field.unknown_filter_field', [
                'field' => implode(', ', $unknownFieldCodes),
                'entity' => $this->entityType,
                'available' => $fields->isEmpty() ? 'none' : $fields->keys()->implode(', '),
            ]));
        }
```

```php
                if (! isset($supportedOperators[$operator])) {
                    $this->invalid(__('validation.custom_field.unsupported_filter_operator', [
                        'operator' => $operator,
                        'field' => $fieldCode,
                        'supported' => implode(', ', array_keys($supportedOperators)),
                    ]));
                }
```

Import `App\Support\CustomFields\WorkspaceCustomFields`. Remove the now-unused query imports.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php tests/Feature/Mcp/CustomFieldWritesTest.php tests/Feature/Api/V1/CustomFieldWritesApiTest.php`
Expected: all pass. The two write-path files prove `translates()` kept write behavior.

- [ ] **Step 8: Gates and commit**

Run the per-task gates from Global Constraints.

```bash
git add app/Mcp/Schema/CustomFieldFilterSchema.php app/Mcp/Filters/CustomFieldFilter.php app/Support/CustomFields/CustomFieldOptionMap.php app/Support/CustomFields/CustomFieldInput.php lang/en/validation.php tests/Feature/Mcp/Filters/CustomFieldFilterTest.php
git commit -m "fix(filters): share the filterable-field rule between schema and engine"
```

---

### Task 3: Labels or IDs for choice filters, any-of lists, and a list cap

**Files:**
- Modify: `app/Mcp/Schema/CustomFieldFilterSchema.php` (`operatorsForType()`, new `MAX_LIST_VALUES`, new `listOperators()`)
- Modify: `app/Mcp/Filters/CustomFieldFilter.php` (`__invoke()`, `normalizeOperand()`, `applyCondition()`, new `resolveOptions()`, `optionId()`, `containsAny()`)
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`
- Test: `tests/Feature/Api/V1/OpportunitiesApiTest.php`

**Interfaces:**
- Consumes: `CustomFieldOptionMap::fromFields()`, `idFor()`, `isAmbiguous()`, `translates()` (Task 2); `filterableFields()` (Task 2).
- Produces:
  - `CustomFieldFilterSchema::MAX_LIST_VALUES` (public const int, 100)
  - `CustomFieldFilterSchema::listOperators(array $operators): array` (private static; array operand schema `['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100]`)
  - `CustomFieldFilter::containsAny(Builder $query, string $valueColumn, array $values): Builder` (private; Task 4 reuses it)

- [ ] **Step 1: Fix the fixtures that store label strings**

In `CustomFieldFilterTest.php`, add a helper:

```php
function filterTestOptionId(CustomField $field, string $label): string
{
    return (string) $field->options()->where('name', $label)->value('id');
}
```

In `it('filters by custom field equality')`, replace the two saves and the filter value:

```php
    $opportunity1->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Proposal/Price Quote'));
    $opportunity2->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Prospecting'));
```

```php
                'stage' => ['eq' => 'Proposal/Price Quote'],
```

In `it('accepts a single value for an array operand')`, replace the save:

```php
    $opportunity->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Qualification'));
```

In `OpportunitiesApiTest.php`, in `it('can filter opportunities by a single-value in operand sent as a query string')`:

```php
        $matched->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Qualification')->getKey());
        $unmatched->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Prospecting')->getKey());

        $response = $this->getJson('/api/v1/opportunities?filter[custom_fields][stage][in]=Qualification')
```

In `it('can filter opportunities by a comma separated in operand sent as a query string')`:

```php
        $proposal->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Qualification')->getKey());
        $prospecting->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Prospecting')->getKey());
        $won->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Closed Won')->getKey());

        $response = $this->getJson('/api/v1/opportunities?filter[custom_fields][stage][in]=Qualification,Prospecting')
```

- [ ] **Step 2: Write the failing tests**

Add to `CustomFieldFilterTest.php` (import `App\Actions\CustomFields\CreateCustomField` and `Illuminate\Support\Str`):

```php
function filterTestStageField(Workspace $workspace): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();
}

it('matches a choice option by its label, its id, or a differently cased label', function (Closure $operand): void {
    $stage = filterTestStageField($this->workspace);
    $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Won Deal']);
    $lost = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Lost Deal']);
    $won->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Won'));
    $lost->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Lost'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['eq' => $operand($stage)]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertDontSee('Lost Deal');
})->with([
    'label' => [fn (): Closure => fn (CustomField $stage): string => 'Closed Won'],
    'option id' => [fn (): Closure => fn (CustomField $stage): string => filterTestOptionId($stage, 'Closed Won')],
    'cased and padded label' => [fn (): Closure => fn (CustomField $stage): string => '  closed won '],
]);

it('resolves a list mixing a label and an option id', function (): void {
    $stage = filterTestStageField($this->workspace);
    $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Won Deal']);
    $qualified = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    $lost = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Lost Deal']);
    $won->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Won'));
    $qualified->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Qualification'));
    $lost->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Lost'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['in' => ['Closed Won', filterTestOptionId($stage, 'Qualification')]]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertSee('Qualified Deal')
        ->assertDontSee('Lost Deal');
});

it('rejects an unknown option label and lists the valid labels', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['eq' => 'Nope']]])
        ->assertHasErrors(['option "Nope" is not one of: Prospecting, Qualification']);
});

it('rejects an option id that no longer exists', function (): void {
    $staleId = (string) Str::ulid();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['eq' => $staleId]]])
        ->assertHasErrors(["option \"{$staleId}\" is not one of"]);
});

it('matches a record holding any one of the requested multi-select options', function (): void {
    $temperature = app(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Temperature',
        'code' => 'temperature',
        'type' => 'multi-select',
        'options' => ['Hot', 'Warm', 'Cold'],
    ]);
    $hot = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Hot Deal']);
    $warm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Warm Deal']);
    $cold = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Cold Deal']);
    $hot->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Hot')]);
    $warm->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Warm')]);
    $cold->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Cold')]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['temperature' => ['has_any' => ['Hot', 'Warm']]]])
        ->assertOk()
        ->assertSee('Hot Deal')
        ->assertSee('Warm Deal')
        ->assertDontSee('Cold Deal');
});

it('matches free-text tags by their raw value without an option lookup', function (): void {
    $labels = app(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Labels',
        'code' => 'labels',
        'type' => 'tags-input',
    ]);
    $urgent = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Urgent Deal']);
    $calm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Calm Deal']);
    $urgent->saveCustomFieldValue($labels, ['urgent']);
    $calm->saveCustomFieldValue($labels, ['someday']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['labels' => ['has_any' => ['urgent']]]])
        ->assertOk()
        ->assertSee('Urgent Deal')
        ->assertDontSee('Calm Deal');
});

it('rejects a list operand longer than one hundred values', function (): void {
    $values = array_map(fn (int $i): string => "Value {$i}", range(1, 101));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['in' => $values]]])
        ->assertHasErrors(['stage: pass at most 100 values.']);
});
```

Update the existing schema assertion to the new list operand shape:

```php
            ->where('filterable_fields.emails.properties', ['has_any' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100]])
            ->where('filterable_fields.phone_number.properties', ['has_any' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100]])
            ->where('filterable_fields.linkedin.properties', ['has_any' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100]])
```

Add to `OpportunitiesApiTest.php` inside `describe('filtering and sorting')` (import `App\Actions\CustomFields\CreateCustomField` if missing):

```php
    it('rejects an unknown option label with a 422', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?filter[custom_fields][stage][eq]=Nope')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter');
    });

    it('carries a label containing a comma through the array form', function (): void {
        Sanctum::actingAs($this->user);

        $priority = app(CreateCustomField::class)->execute($this->user, [
            'entity_type' => 'opportunity',
            'name' => 'Priority',
            'code' => 'deal_priority',
            'type' => 'select',
            'options' => ['Hot, urgent', 'Warm'],
        ]);
        $hot = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Hot Deal']);
        $warm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Warm Deal']);
        $hot->saveCustomFieldValue($priority, (string) $priority->options()->where('name', 'Hot, urgent')->value('id'));
        $warm->saveCustomFieldValue($priority, (string) $priority->options()->where('name', 'Warm')->value('id'));

        $response = $this->getJson('/api/v1/opportunities?filter[custom_fields][deal_priority][in][]='.urlencode('Hot, urgent'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($hot->id)->not->toContain($warm->id);
    });
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php`
Expected: label, mixed list, unknown label, stale id, multi-select any-of, list cap, schema shape, 422 and comma tests FAIL. The ID dataset row and tags test may already pass.

- [ ] **Step 4: Publish list operands as arrays with a cap**

In `CustomFieldFilterSchema`, add `public const int MAX_LIST_VALUES = 100;`, keep the existing `MULTI_OPERATORS` constant, and add:

```php
    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, mixed>>
     */
    private static function listOperators(array $operators): array
    {
        $result = [];

        foreach ($operators as $op) {
            $result[$op] = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::MAX_LIST_VALUES];
        }

        return $result;
    }
```

In `operatorsForType()`, change these arms:

```php
            CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK => self::listOperators(self::MULTI_OPERATORS),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => array_merge(
                self::buildOperators(['eq'], 'string'),
                self::listOperators(['in']),
            ),
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::listOperators(self::MULTI_OPERATORS),
```

Update the `@return` docblock of `operatorsForType()` to `array<string, array<string, mixed>>`.

- [ ] **Step 5: Cap list operands in the engine**

In `CustomFieldFilter::normalizeOperand()`, after `$normalized` is computed and before `return $normalized;`:

```php
        if (is_array($normalized) && count($normalized) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            $this->invalid(__('validation.custom_field.too_many_values', [
                'field' => $fieldCode,
                'max' => CustomFieldFilterSchema::MAX_LIST_VALUES,
            ]));
        }
```

- [ ] **Step 6: Resolve choice operands**

In `__invoke()`, after the unknown-code check, build the option map for translating fields:

```php
        $optionMap = resolve(CustomFieldOptionMap::class);
        $options = $optionMap->fromFields(
            $fields->only($fieldCodes)->filter($optionMap->translates(...))->values(),
        );
```

In the operator loop, after `normalizeOperand(...)`:

```php
                $operand = $this->resolveOptions($optionMap, (string) $fieldCode, $options[$fieldCode] ?? null, $operand);
```

Add the methods:

```php
    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}|null  $entry
     */
    private function resolveOptions(CustomFieldOptionMap $optionMap, string $fieldCode, ?array $entry, mixed $operand): mixed
    {
        if ($entry === null || is_bool($operand)) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (string $value): string => $this->optionId($optionMap, $fieldCode, $entry, $value), $operand);
        }

        return $this->optionId($optionMap, $fieldCode, $entry, (string) $operand);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionId(CustomFieldOptionMap $optionMap, string $fieldCode, array $entry, string $value): string
    {
        $id = $optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($optionMap->isAmbiguous($entry, $value)) {
            $this->invalid(__('validation.custom_field.ambiguous_option', ['field' => $fieldCode, 'value' => $value]));
        }

        $this->invalid(__('validation.custom_field.unknown_option', [
            'field' => $fieldCode,
            'value' => $value,
            'labels' => $entry['labels'] === [] ? 'none' : implode(', ', $entry['labels']),
        ]));
    }
```

Import `App\Support\CustomFields\CustomFieldOptionMap`.

- [ ] **Step 7: Make `has_any` match any element**

In `applyCondition()`, change the `has_any` arm and add the helper:

```php
                'has_any' => $this->containsAny($q, $valueColumn, (array) $operand),
```

```php
    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $values
     * @return Builder<Model>
     */
    private function containsAny(Builder $query, string $valueColumn, array $values): Builder
    {
        return $query->where(function (Builder $anyValue) use ($valueColumn, $values): void {
            foreach ($values as $value) {
                $anyValue->orWhereJsonContains($valueColumn, [$value]);
            }
        });
    }
```

The jsonb `?|` operator is not used because `?` collides with PDO placeholders.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php tests/Feature/Api/V1/PeopleApiTest.php`
Expected: all pass. If `PeopleApiTest.php` does not exist, run `tests/Feature/Api/V1` instead.

- [ ] **Step 9: Gates and commit**

```bash
git add app/Mcp/Schema/CustomFieldFilterSchema.php app/Mcp/Filters/CustomFieldFilter.php tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php
git commit -m "feat(filters): accept option labels and match any listed value"
```

---

### Task 4: `not_in`, `has_none` and `is_empty`

**Files:**
- Modify: `app/Mcp/Schema/CustomFieldFilterSchema.php` (`operatorsForType()`, new `withEmptiness()`)
- Modify: `app/Mcp/Filters/CustomFieldFilter.php` (`applyCondition()`, new `hasValue()`)
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`
- Test: `tests/Feature/Api/V1/OpportunitiesApiTest.php`

**Interfaces:**
- Consumes: `containsAny()` and `listOperators()` (Task 3).
- Produces: operators `not_in` (single choice), `has_none` (multi choice, email, phone, link), `is_empty` (every filterable type, boolean operand).

- [ ] **Step 1: Write the failing tests**

Add to `CustomFieldFilterTest.php`:

```php
it('includes records with no value when excluding single-choice options', function (): void {
    $stage = filterTestStageField($this->workspace);
    $qualified = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    $prospect = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Prospect Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Unstaged Deal']);
    $qualified->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Qualification'));
    $prospect->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Prospecting'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['not_in' => ['Prospecting']]]])
        ->assertOk()
        ->assertSee('Qualified Deal')
        ->assertSee('Unstaged Deal')
        ->assertDontSee('Prospect Deal');
});

it('includes records with no value when excluding multi-choice options', function (): void {
    $temperature = app(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Temperature',
        'code' => 'temperature',
        'type' => 'multi-select',
        'options' => ['Hot', 'Warm'],
    ]);
    $hot = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Hot Deal']);
    $warm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Warm Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Untagged Deal']);
    $hot->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Hot')]);
    $warm->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Warm')]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['temperature' => ['has_none' => ['Hot']]]])
        ->assertOk()
        ->assertSee('Warm Deal')
        ->assertSee('Untagged Deal')
        ->assertDontSee('Hot Deal');
});

it('treats a missing row, a null, a blank string and an empty array as empty', function (string $type, array $options, Closure $emptyValue, Closure $filledValue): void {
    $field = app(CreateCustomField::class)->execute($this->user, array_filter([
        'entity_type' => 'opportunity',
        'name' => 'Probe',
        'code' => 'probe',
        'type' => $type,
        'options' => $options,
    ]));
    $stored = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Stored Empty']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Never Set']);
    $filled = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Has Value']);
    $stored->saveCustomFieldValue($field, $emptyValue($field));
    $filled->saveCustomFieldValue($field, $filledValue($field));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['probe' => ['is_empty' => true]]])
        ->assertOk()
        ->assertSee('Stored Empty')
        ->assertSee('Never Set')
        ->assertDontSee('Has Value');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['probe' => ['is_empty' => false]]])
        ->assertOk()
        ->assertSee('Has Value')
        ->assertDontSee('Stored Empty')
        ->assertDontSee('Never Set');
})->with([
    'text blank string' => ['text', [], fn (): Closure => fn (CustomField $field): string => '', fn (): Closure => fn (CustomField $field): string => 'Acme'],
    'number null' => ['number', [], fn (): Closure => fn (CustomField $field): ?int => null, fn (): Closure => fn (CustomField $field): int => 5],
    'multi-select empty array' => ['multi-select', ['Hot'], fn (): Closure => fn (CustomField $field): array => [], fn (): Closure => fn (CustomField $field): array => [filterTestOptionId($field, 'Hot')]],
]);

it('rejects an empty exclusion list instead of matching everything', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['not_in' => []]]])
        ->assertHasErrors(['must be an array of strings']);
});
```

Extend the schema assertion:

```php
            ->where('filterable_fields.emails.properties', [
                'has_any' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
                'has_none' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
                'is_empty' => ['type' => 'boolean'],
            ])
```

Apply the same three-key shape to `phone_number` and `linkedin`, and rename the test to `it('publishes list and emptiness operators for email, phone, and link fields', ...)`.

Add to `OpportunitiesApiTest.php` inside `describe('filtering and sorting')`:

```php
    it('reads is_empty from a query string boolean', function (string $raw, bool $expectsEmpty): void {
        Sanctum::actingAs($this->user);

        $stage = CustomField::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->workspace->id)
            ->where('entity_type', 'opportunity')
            ->where('code', 'stage')
            ->firstOrFail();
        $staged = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Staged Deal']);
        $unstaged = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Unstaged Deal']);
        $staged->saveCustomFieldValue($stage, (string) $stage->options->firstWhere('name', 'Qualification')->getKey());

        $ids = collect($this->getJson("/api/v1/opportunities?filter[custom_fields][stage][is_empty]={$raw}")->assertOk()->json('data'))->pluck('id');

        $expectsEmpty
            ? expect($ids)->toContain($unstaged->id)->not->toContain($staged->id)
            : expect($ids)->toContain($staged->id)->not->toContain($unstaged->id);
    })->with([
        'one' => ['1', true],
        'true' => ['true', true],
        'zero' => ['0', false],
        'false' => ['false', false],
    ]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php`
Expected: the new tests FAIL with "is not supported" errors, and the schema assertion fails. The empty-list test may already pass, which is fine: it pins existing behavior.

- [ ] **Step 3: Publish the operators**

In `CustomFieldFilterSchema`, add:

```php
    /**
     * @param  array<string, array<string, mixed>>  $operators
     * @return array<string, array<string, mixed>>
     */
    private static function withEmptiness(array $operators): array
    {
        return [...$operators, 'is_empty' => ['type' => 'boolean']];
    }
```

Replace the body of the `match` in `operatorsForType()`:

```php
        return match ($fieldType) {
            CustomFieldType::TEXT => self::withEmptiness(self::buildOperators(self::STRING_OPERATORS, 'string')),
            CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::withEmptiness(self::listOperators(['has_any', 'has_none'])),
            CustomFieldType::CURRENCY => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'number')),
            CustomFieldType::NUMBER => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'integer')),
            CustomFieldType::DATE, CustomFieldType::DATE_TIME => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'string')),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => self::withEmptiness(self::buildOperators(self::BOOLEAN_OPERATORS, 'boolean')),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => self::withEmptiness(array_merge(
                self::buildOperators(['eq'], 'string'),
                self::listOperators(['in', 'not_in']),
            )),
            default => [],
        };
```

Delete the `MULTI_OPERATORS` constant if nothing else uses it.

- [ ] **Step 4: Apply the operators in the engine**

Replace `applyCondition()` with:

```php
    /**
     * @param  Builder<Model>  $query
     */
    private function applyCondition(
        Builder $query,
        CustomField $field,
        string $valueColumn,
        string $operator,
        mixed $operand,
    ): void {
        match ($operator) {
            'not_in' => $query->whereDoesntHave('customFieldValues', fn (Builder $q): Builder => $q
                ->where('custom_field_id', $field->getKey())
                ->whereIn($valueColumn, (array) $operand)),
            'has_none' => $query->whereDoesntHave('customFieldValues', fn (Builder $q): Builder => $this->containsAny(
                $q->where('custom_field_id', $field->getKey()),
                $valueColumn,
                (array) $operand,
            )),
            'is_empty' => $operand === true
                ? $query->whereDoesntHave('customFieldValues', fn (Builder $q): Builder => $this->hasValue($q, $field, $valueColumn))
                : $query->whereHas('customFieldValues', fn (Builder $q): Builder => $this->hasValue($q, $field, $valueColumn)),
            default => $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
                $q->where('custom_field_id', $field->getKey());

                match ($operator) {
                    'eq', 'gt', 'gte', 'lt', 'lte' => $q->where($valueColumn, self::OPERATOR_MAP[$operator], $operand),
                    'contains' => $q->where($valueColumn, 'ILIKE', '%'.LikePattern::escape((string) $operand).'%'),
                    'in' => $q->whereIn($valueColumn, $operand),
                    'has_any' => $this->containsAny($q, $valueColumn, (array) $operand),
                    default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
                };
            }),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function hasValue(Builder $query, CustomField $field, string $valueColumn): Builder
    {
        $query->where('custom_field_id', $field->getKey())->whereNotNull($valueColumn);

        return match ($valueColumn) {
            'json_value' => $query->whereRaw("json_value::jsonb not in ('[]'::jsonb, 'null'::jsonb)"),
            'string_value', 'text_value' => $query->where($valueColumn, '<>', ''),
            default => $query,
        };
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1 tests/Feature/Mcp`
Expected: all pass.

- [ ] **Step 6: Gates and commit**

```bash
git add app/Mcp/Schema/CustomFieldFilterSchema.php app/Mcp/Filters/CustomFieldFilter.php tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/OpportunitiesApiTest.php
git commit -m "feat(filters): add not_in, has_none and is_empty operators"
```

---

### Task 5: Chat uses the shared engine

**Files:**
- Delete: `packages/Chat/src/Services/Tools/CustomFieldsFilterTranslator.php`
- Modify: `packages/Chat/src/Tools/BaseReadListTool.php` (`buildHttpRequest()` and the action `try` block in `handle()`)
- Modify: `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php` (`describe()` header lines)
- Test: `tests/Feature/Chat/ListToolFilterTest.php`

**Interfaces:**
- Consumes: the engine's label resolution and messages (Tasks 2 to 4). `ReportsValidationFailures::validationError(ValidationException $exception): string`, already used by `BaseReadListTool`.
- Produces: nothing new.

- [ ] **Step 1: Update the one stale assertion and add a regression test**

In `ListToolFilterTest.php`, `it('rejects an unknown option label instead of silently returning everything')`, change:

```php
        ->and($result['error'])->toContain('is not one of');
```

Add:

```php
it('excludes options and keeps tasks without a status', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $open = Task::factory()->for($workspace)->create(['title' => 'Open one']);
    $done = Task::factory()->for($workspace)->create(['title' => 'Finished']);
    Task::factory()->for($workspace)->create(['title' => 'No status']);
    $open->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'To do'));
    $done->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'Done'));

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'custom_fields' => ['status' => ['not_in' => ['Done']]],
    ])));

    TenantContextService::setTenantId(null);

    expect(collect($rows)->pluck('attributes.title')->sort()->values()->all())->toBe(['No status', 'Open one']);
});
```

- [ ] **Step 2: Run the chat tests to see the baseline**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Chat/ListToolFilterTest.php`
Expected: the updated assertion FAILS (the translator still says "not one of the options"). The new test passes, because the translator forwards `not_in` to the engine.

- [ ] **Step 3: Remove the translator**

```bash
git rm packages/Chat/src/Services/Tools/CustomFieldsFilterTranslator.php
grep -rn "CustomFieldsFilterTranslator" app packages tests
```

Expected: only `BaseReadListTool.php` still references it. If a dedicated translator test file exists, delete it too; its cases are covered by `CustomFieldFilterTest` and `ListToolFilterTest`.

In `BaseReadListTool::buildHttpRequest()`, replace the translator call:

```php
        $customFields = $request['custom_fields'] ?? null;

        if (is_array($customFields) && $customFields !== []) {
            $nativeFilters['custom_fields'] = $customFields;
        }
```

Remove the translator import. In `handle()`, extend the action `try` block so engine validation errors reach the model instead of crashing the tool:

```php
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (InvalidQuery $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }
```

- [ ] **Step 4: Update the describer header**

In `CustomFieldsFilterDescriber::describe()`, replace the second header line:

```php
            'For choice fields pass the option label as listed; an option ID also works. not_in and has_none also match records where the field is empty. is_empty takes true or false.',
```

- [ ] **Step 5: Run the chat tests to verify they pass**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Chat/ListToolFilterTest.php tests/Feature/Chat/ListDateFilterTest.php tests/Feature/CRM/SurfaceParityTest.php`
Expected: all pass.

- [ ] **Step 6: Gates and commit**

Run the per-task gates, plus `composer test:type-coverage`.

```bash
git add packages/Chat/src/Tools/BaseReadListTool.php packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php tests/Feature/Chat/ListToolFilterTest.php
git commit -m "refactor(chat): filter custom fields through the shared engine"
```

---

### Task 6: Agent-facing descriptions and docs

**Files:**
- Modify: `app/Mcp/Tools/BaseListTool.php` (`schema()`, the `filter` entry)
- Modify: `app/Mcp/Resources/CompanySchemaResource.php:80`, `NoteSchemaResource.php:93`, `OpportunitySchemaResource.php:79`, `PeopleSchemaResource.php:78`, `TaskSchemaResource.php:98` (the `usage` string)
- Modify: `app/Http/Requests/Api/V1/IndexRequest.php` (new `queryParameters()`)
- Modify: `packages/Documentation/resources/content/docs/guides/mcp.md` (after the list-tools paragraph near line 245)
- Modify: `packages/Documentation/resources/content/help/getting-started/find-anything-with-search-and-filters.md` (the "make it searchable" section)

**Interfaces:**
- Consumes: the final operator set (Task 4).
- Produces: nothing code-facing.

- [ ] **Step 1: MCP `filter` description**

In `BaseListTool::schema()`:

```php
                'filter' => $schema->object()->description('Filter by custom field values. Keys are codes from get-crm-schema filterable_fields; each value is an operator object. Single choice: eq, in, not_in. Multi choice, email, phone, link: has_any, has_none. Text: eq, contains. Numbers and dates: eq, gt, gte, lt, lte. Every type: is_empty (true or false). Choice values take an option label or ID, listed under custom_fields in get-crm-schema. not_in and has_none also match records where the field is empty.'),
```

- [ ] **Step 2: Schema resource `usage` strings**

Set the same `usage` value in all five schema resources:

```php
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Filter list tools with the "filter" param: an object keyed by field code, each value an operator object such as {"eq": "Closed Won"}, {"not_in": ["Done"]} or {"is_empty": true}. Operators per field are listed in filterable_fields. Choice values take an option label or ID.',
```

Keep any entity-specific example sentence that the current string carries after the operator list (the Company resource has `Example: {"name": "Acme", "custom_fields": {"icp": true}}.`); append it unchanged.

- [ ] **Step 3: REST reference parameters**

In `IndexRequest`, add:

```php
    /**
     * @return array<string, array{description: string, example: string}>
     */
    public function queryParameters(): array
    {
        return [
            'filter[custom_fields][{code}][{operator}]' => [
                'description' => 'Filter by a custom field value. Single choice: eq, in, not_in. Multi choice, email, phone, link: has_any, has_none. Text: eq, contains. Numbers and dates: eq, gt, gte, lt, lte. Every type: is_empty (1 or 0). Choice values take an option label or ID; an unknown one returns 422. Separate list values with commas, or repeat the parameter with [] when a label contains a comma. not_in and has_none also match records where the field is empty. Up to 10 conditions, 100 values per list.',
                'example' => 'filter[custom_fields][stage][in]=Qualification,Prospecting',
            ],
        ];
    }
```

Run: `php artisan scribe:generate` and confirm the parameter appears under each list endpoint in `public/docs` (not tracked by git). If Scribe rejects the key format, read `vendor/knuckleswtf/scribe/src/Extracting/Strategies/QueryParameters/GetFromFormRequest.php` for the accepted shape and adjust.

- [ ] **Step 4: MCP guide**

After the paragraph that starts "Entity list tools support `search`", insert:

```markdown
### Filter by custom fields

Pass `filter` as an object keyed by field code. Each value is an operator object. `get-crm-schema-tool` lists the operators for every field under `filterable_fields`, and every choice option under `custom_fields`.

| Field type | Operators |
|---|---|
| Single choice (select, radio, toggle buttons) | `eq`, `in`, `not_in`, `is_empty` |
| Multi choice, email, phone, link | `has_any`, `has_none`, `is_empty` |
| Text | `eq`, `contains`, `is_empty` |
| Number, currency, date, date and time | `eq`, `gt`, `gte`, `lt`, `lte`, `is_empty` |
| Checkbox, toggle | `eq`, `is_empty` |

Choice values take the option label or its ID. An unknown label returns an error that lists the valid ones. `not_in` and `has_none` also match records where the field is empty. `is_empty` takes `true` or `false`. Conditions combine with AND, up to 10 per call and 100 values per list.

    {"stage": {"not_in": ["Closed Won", "Closed Lost"]}, "amount": {"gte": 10000}}
```

- [ ] **Step 5: Correct the help page after checking the UI**

Use the `agent-browser-relaticle` skill. Open the Companies list on the local app, open the Filter panel, and note which custom fields appear. Then open the custom fields settings and compare against each field's "Searchable" and "Visible in list" settings. The code (`BaseBuilder` with `visibleInList()`, and `isFilterable()` per type) says the panel shows choice, tags and record fields that are visible in the list, regardless of Searchable. Rewrite the section heading and paragraph to match what the UI shows, for example:

```markdown
## If you can't find a filter for a field you need

The filter panel shows choice, tags and record fields that are visible in the list. Open
```

Keep the rest of the section's steps accurate to the settings screen. Save screenshots under `.context/`. Do not put a bare `: ` inside the YAML front matter.

- [ ] **Step 6: Run the affected tests**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/Mcp tests/Feature/CRM/SurfaceParityTest.php tests/Smoke tests/Arch`
Expected: all pass. `tests/Arch/ConventionsTest.php` enforces no em-dash in these paths.

- [ ] **Step 7: Gates and commit**

```bash
git add app/Mcp/Tools/BaseListTool.php app/Mcp/Resources app/Http/Requests/Api/V1/IndexRequest.php packages/Documentation/resources/content/docs/guides/mcp.md packages/Documentation/resources/content/help/getting-started/find-anything-with-search-and-filters.md
git commit -m "docs(filters): document custom field filter operators"
```

---

### Task 7: Pin the package fix and prove both engines agree

Blocked until Task 1's release exists.

**Files:**
- Modify: `composer.json` (`relaticle/custom-fields` constraint), `composer.lock`
- Test: `tests/Feature/CRM/SurfaceParityTest.php`

**Interfaces:**
- Consumes: the released package version from Task 1; the engine from Tasks 2 to 4.
- Produces: the contract test.

- [ ] **Step 1: Write the contract test**

Append to `SurfaceParityTest.php` (imports: `App\Actions\CustomFields\CreateCustomField`, `App\Filament\Resources\OpportunityResource\Pages\ListOpportunities`, `App\Models\Opportunity`, `Filament\Facades\Filament`, `Laravel\Sanctum\Sanctum`):

```php
it('narrows a list to the same records through the table filter and the api', function (string $type, string $operator, bool $usesOptions): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();
    $this->actingAs($user);
    Filament::setTenant($workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $field = app(CreateCustomField::class)->execute($user, array_filter([
        'entity_type' => 'opportunity',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => $type,
        'options' => $usesOptions ? ['Enterprise', 'Mid-Market', 'SMB'] : null,
    ]));
    $stored = fn (string $label): string => $usesOptions
        ? (string) $field->options()->where('name', $label)->value('id')
        : $label;
    $value = fn (string $label): string|array => $type === 'select' ? $stored($label) : [$stored($label)];

    $enterprise = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Enterprise Deal']);
    $midMarket = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Mid-Market Deal']);
    $smb = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'SMB Deal']);
    $blank = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Unsegmented Deal']);
    $enterprise->saveCustomFieldValue($field, $value('Enterprise'));
    $midMarket->saveCustomFieldValue($field, $value('Mid-Market'));
    $smb->saveCustomFieldValue($field, $value('SMB'));

    livewire(ListOpportunities::class)
        ->filterTable('custom_fields.segment', [$stored('Enterprise'), $stored('Mid-Market')])
        ->assertCanSeeTableRecords([$enterprise, $midMarket])
        ->assertCanNotSeeTableRecords([$smb, $blank]);

    Sanctum::actingAs($user);

    $apiIds = collect($this->getJson("/api/v1/opportunities?filter[custom_fields][segment][{$operator}]=Enterprise,Mid-Market")->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($apiIds)->toBe(collect([$enterprise->id, $midMarket->id])->sort()->values()->all());
})->with([
    'single choice' => ['select', 'in', true],
    'multi choice' => ['multi-select', 'has_any', true],
    'free-text tags' => ['tags-input', 'has_any', false],
]);
```

If the Livewire assertions report the field's filter as missing, the field is not visible in the list. Read `CreateCustomField` for how it sets `settings.visible_in_list` and pass it explicitly.

- [ ] **Step 2: Run it against the old package**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/CRM/SurfaceParityTest.php --filter="same records"`
Expected: `single choice` passes; `multi choice` and `free-text tags` FAIL on the Livewire assertion, because package 3.10.0 still requires every picked value.

- [ ] **Step 3: Bump the package**

Set `"relaticle/custom-fields": "^3.10.1"` in `composer.json` (use the version Task 1 released; the patch minimum is required because the fix lives in it), then:

```bash
composer update relaticle/custom-fields --with-dependencies --no-interaction
```

- [ ] **Step 4: Run it again**

Run: `DB_DATABASE=relaticle_filter_contract_testing php artisan test --compact tests/Feature/CRM/SurfaceParityTest.php`
Expected: all pass.

- [ ] **Step 5: Browser check of the UI change**

Use the `agent-browser-relaticle` skill on the local app. On Opportunities, create or use a multi-select field with two records holding different single options. Filter by both options and confirm both records show. Repeat for a tags field. Capture light and dark screenshots under `.context/`.

- [ ] **Step 6: Gates and commit**

```bash
git add composer.json composer.lock tests/Feature/CRM/SurfaceParityTest.php
git commit -m "test(filters): prove table and api filters return the same records"
```

---

### Task 8: Final verification and rollout checks

**Files:** none changed unless a check fails.

- [ ] **Step 1: Query plan on 50k records**

Create a scratch database, seed one workspace with 50,000 opportunities and stage values, and capture plans:

```bash
psql -h 127.0.0.1 -U root -d postgres -c "create database relaticle_filter_perf"
DB_DATABASE=relaticle_filter_perf php artisan migrate --force --no-interaction
```

In `DB_DATABASE=relaticle_filter_perf php artisan tinker`, create a user with a personal workspace through `User::factory()->withPersonalWorkspace()->create()`, insert opportunities with `Opportunity::factory()->recycle([$user, $workspace])->count(1000)->create()` fifty times, and save stage values on two thirds of them. Then build the `ListOpportunities` query with `filter[custom_fields][stage][not_in]=Prospecting` and with `[is_empty]=1`, take `->toRawSql()`, and run `EXPLAIN ANALYZE` on each in psql.

Expected: the anti-join on `custom_field_values` uses `custom_field_values_entity_id_custom_field_id_index` or `custom_field_values_entity_type_unique`, and each query finishes well under 100 ms. Record both plans in the PR body. Drop the scratch database afterwards.

- [ ] **Step 2: Production orphan count (read-only, ask first)**

Ask the user before touching production. With approval, run over the read-only SSH psql path:

```sql
select f.type, count(*) as orphaned
from custom_field_values v
join custom_fields f on f.id = v.custom_field_id
where f.type in ('select', 'radio', 'toggle-buttons')
  and v.string_value is not null
  and not exists (select 1 from custom_field_options o where o.id::text = v.string_value)
group by f.type;
```

If the count is material, propose running `php artisan custom-fields:cleanup-orphaned-values` before the release. Orphaned values cannot be targeted by a filter after this change, and they count as not empty.

- [ ] **Step 3: Full gates once**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
composer test:type-coverage
composer test:lint
DB_DATABASE=relaticle_filter_contract_testing composer test:pest:full
```

Expected: all green. Never label a failure pre-existing without a CI run on `main` that shows it.

- [ ] **Step 4: Sweep for leftovers**

```bash
grep -rn "CustomFieldsFilterTranslator\|skipsOptionTranslation\|resolveFields(" app packages tests
grep -rn "has_any\b" app/Mcp packages/Chat/src | grep -v "has_none"
```

Expected: the first grep is empty. Review the second for any description still claiming the old operator set.

- [ ] **Step 5: Hand off**

Draft the PR body for the user's approval: summary, the two behavior changes (API 422 on an unknown option; UI multi-select and tags filters now match any picked value), the query plans, the orphan count, and screenshots. Push and open the PR only after the user says so.
