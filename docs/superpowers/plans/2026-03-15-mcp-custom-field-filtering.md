# MCP Custom Field Filtering Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable MCP List tools to filter/sort by custom field values, expand relationships inline, and provide CRM summary aggregation.

**Architecture:** Extend existing BaseListTool with optional `filter`, `sort`, and `include` parameters. Custom field filtering uses a Spatie QueryBuilder `AllowedFilter::custom()` invokable class that translates operator objects into `whereHas('customFieldValues', ...)` queries. A new CRM Summary MCP Resource provides aggregate stats via SQL aggregates.

**Tech Stack:** Laravel 12, Laravel MCP, Spatie QueryBuilder v6, PostgreSQL, Pest v4

**Spec:** `docs/superpowers/specs/2026-03-15-mcp-custom-field-filtering-design.md`

**Skills to activate:** `@mcp-development`, `@pest-testing`, `@custom-fields-development`, `@spatie-laravel-php-standards`

---

## Chunk 1: Database Migration & CustomFieldFilter

### Task 0: Add missing cases to CustomFieldType enum

**Files:**
- Modify: `app/Enums/CustomFieldType.php`

The enum docblock says "Maps to the field types available in the relaticle/custom-fields package" but is missing `FILE_UPLOAD` and `RECORD` cases. These are needed for type-safe exclusion in the filter schema.

- [ ] **Step 1: Add missing enum cases**

Add to `app/Enums/CustomFieldType.php` after the `MULTI_SELECT` case:

```php
    case FILE_UPLOAD = 'file-upload';
    case RECORD = 'record';
```

- [ ] **Step 2: Run phpstan to verify no breakage**

```bash
vendor/bin/phpstan analyse app/Enums/CustomFieldType.php
```

- [ ] **Step 3: Commit**

```bash
git add app/Enums/CustomFieldType.php
git commit -m "chore: add FILE_UPLOAD and RECORD cases to CustomFieldType enum"
```

---

### Task 1: Add performance indexes migration

**Files:**
- Create: `database/migrations/2026_03_16_000001_add_custom_field_value_filtering_indexes.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_custom_field_value_filtering_indexes --no-interaction
```

- [ ] **Step 2: Write the migration (up only, no down)**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_values', function (Blueprint $table): void {
            $table->index(['custom_field_id', 'float_value'], 'cfv_field_float_idx');
            $table->index(['custom_field_id', 'date_value'], 'cfv_field_date_idx');
            $table->index(['custom_field_id', 'datetime_value'], 'cfv_field_datetime_idx');
            $table->index(['custom_field_id', 'string_value'], 'cfv_field_string_idx');
            $table->index(['custom_field_id', 'integer_value'], 'cfv_field_integer_idx');
            $table->index(['custom_field_id', 'boolean_value'], 'cfv_field_boolean_idx');
        });
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

Expected: Migration completes successfully. Verify with `php artisan migrate:status`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_add_custom_field_value_filtering_indexes.php
git commit -m "feat: add performance indexes for custom field value filtering"
```

---

### Task 2: Create CustomFieldFilter (Spatie Filter)

**Files:**
- Create: `app/Mcp/Filters/CustomFieldFilter.php`
- Create: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`
- Reference: `vendor/spatie/laravel-query-builder/src/Filters/Filter.php` (interface)
- Reference: `vendor/relaticle/custom-fields/src/Models/CustomFieldValue.php` (getValueColumn)
- Reference: `app/Models/CustomField.php`

- [ ] **Step 1: Write the failing test for basic equality filter**

```bash
php artisan make:test Mcp/Filters/CustomFieldFilterTest --pest --no-interaction
```

```php
<?php

declare(strict_types=1);

use App\Mcp\Filters\CustomFieldFilter;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    actingAs($this->user);
});

it('filters opportunities by custom field equality', function (): void {
    $opportunity1 = Opportunity::factory()->for($this->team)->create(['name' => 'Deal A']);
    $opportunity2 = Opportunity::factory()->for($this->team)->create(['name' => 'Deal B']);

    // Set stage custom field on opportunity1 to "Proposal"
    $stageField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->first();

    if ($stageField) {
        $opportunity1->saveCustomFieldValue($stageField, 'Proposal');
        $opportunity2->saveCustomFieldValue($stageField, 'Prospecting');
    }

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'stage' => ['eq' => 'Proposal'],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters([
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        ])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Deal A');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="filters opportunities by custom field equality"
```

Expected: FAIL -- `CustomFieldFilter` class not found.

- [ ] **Step 3: Implement CustomFieldFilter**

Create `app/Mcp/Filters/CustomFieldFilter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Spatie\QueryBuilder\Filters\Filter;

final readonly class CustomFieldFilter implements Filter
{
    private const int MAX_CONDITIONS = 10;

    private const array OPERATOR_MAP = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    public function __construct(
        private string $entityType,
    ) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $fieldCodes = array_keys($value);

        abort_if(count($fieldCodes) > self::MAX_CONDITIONS, 422, 'Maximum 10 filter conditions allowed.');

        $fields = $this->resolveFields($fieldCodes);

        foreach ($value as $fieldCode => $operators) {
            if (! is_array($operators) || ! isset($fields[$fieldCode])) {
                continue;
            }

            $field = $fields[$fieldCode];
            $valueColumn = CustomFieldValue::getValueColumn($field->type);

            foreach ($operators as $operator => $operand) {
                $this->applyCondition($query, $field, $valueColumn, $operator, $operand);
            }
        }
    }

    private function applyCondition(
        Builder $query,
        CustomField $field,
        string $valueColumn,
        string $operator,
        mixed $operand,
    ): void {
        $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
            $q->where('custom_field_id', $field->getKey());

            match ($operator) {
                'eq', 'gt', 'gte', 'lt', 'lte' => $q->where($valueColumn, self::OPERATOR_MAP[$operator], $operand),
                'contains' => $q->where($valueColumn, 'ILIKE', "%{$operand}%"),
                'in' => $q->whereIn($valueColumn, (array) $operand),
                'has_any' => $q->whereJsonContains($valueColumn, $operand),
                default => null, // silently ignore unknown operators
            };
        });
    }

    /**
     * @param  array<int, string>  $fieldCodes
     * @return Collection<string, CustomField>
     */
    private function resolveFields(array $fieldCodes): Collection
    {
        return once(fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->currentTeam->getKey())
            ->where('entity_type', $this->entityType)
            ->whereIn('code', $fieldCodes)
            ->active()
            ->get()
            ->keyBy('code'));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter="filters opportunities by custom field equality"
```

Expected: PASS

- [ ] **Step 5: Add tests for numeric operators (gte, lte)**

Add to the same test file:

```php
it('filters opportunities by currency field with gte operator', function (): void {
    $opportunity1 = Opportunity::factory()->for($this->team)->create(['name' => 'Big Deal']);
    $opportunity2 = Opportunity::factory()->for($this->team)->create(['name' => 'Small Deal']);

    $amountField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    if ($amountField) {
        $opportunity1->saveCustomFieldValue($amountField, 100000);
        $opportunity2->saveCustomFieldValue($amountField, 5000);
    }

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'amount' => ['gte' => 50000],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters([
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        ])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Big Deal');
});

it('silently ignores unknown field codes', function (): void {
    Opportunity::factory()->for($this->team)->create();

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'nonexistent_field' => ['eq' => 'test'],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters([
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        ])
        ->get();

    expect($results)->toHaveCount(1);
});

it('rejects more than 10 filter conditions', function (): void {
    $filters = [];
    for ($i = 0; $i < 11; $i++) {
        $filters["field_{$i}"] = ['eq' => 'test'];
    }

    $request = new Request([
        'filter' => ['custom_fields' => $filters],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters([
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        ])
        ->get();
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);
```

- [ ] **Step 6: Run all filter tests**

```bash
php artisan test --compact --filter="CustomFieldFilter"
```

Expected: All pass.

- [ ] **Step 7: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Filters/CustomFieldFilter.php tests/Feature/Mcp/Filters/CustomFieldFilterTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/Mcp/Filters/CustomFieldFilter.php tests/Feature/Mcp/Filters/CustomFieldFilterTest.php
git commit -m "feat: add CustomFieldFilter for filtering by custom field values"
```

---

### Task 3: Create CustomFieldSort (Spatie Sort)

**Files:**
- Create: `app/Mcp/Filters/CustomFieldSort.php`
- Create: `tests/Feature/Mcp/Filters/CustomFieldSortTest.php`
- Reference: `vendor/spatie/laravel-query-builder/src/Sorts/Sort.php` (interface)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Mcp\Filters\CustomFieldSort;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    actingAs($this->user);
});

it('sorts opportunities by custom field value ascending', function (): void {
    $opp1 = Opportunity::factory()->for($this->team)->create(['name' => 'A']);
    $opp2 = Opportunity::factory()->for($this->team)->create(['name' => 'B']);

    $amountField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    if ($amountField) {
        $opp1->saveCustomFieldValue($amountField, 50000);
        $opp2->saveCustomFieldValue($amountField, 100000);
    }

    $request = new Request(['sort' => 'amount']);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedSorts([
            AllowedSort::custom('amount', new CustomFieldSort('opportunity')),
        ])
        ->get();

    expect($results->first()->name)->toBe('A')
        ->and($results->last()->name)->toBe('B');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="sorts opportunities by custom field"
```

- [ ] **Step 3: Implement CustomFieldSort**

Create `app/Mcp/Filters/CustomFieldSort.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Spatie\QueryBuilder\Sorts\Sort;

final readonly class CustomFieldSort implements Sort
{
    public function __construct(
        private string $entityType,
    ) {}

    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $field = $this->resolveField($property);

        if (! $field) {
            return;
        }

        $valueColumn = CustomFieldValue::getValueColumn($field->type);
        $model = $query->getModel();

        $query->orderBy(
            CustomFieldValue::query()
                ->select($valueColumn)
                ->whereColumn('entity_id', $model->getTable() . '.id')
                ->where('entity_type', $model->getMorphClass())
                ->where('custom_field_id', $field->getKey())
                ->limit(1),
            $descending ? 'desc' : 'asc',
        );
    }

    private function resolveField(string $code): ?CustomField
    {
        return $this->resolveAllFields()->get($code);
    }

    /**
     * @return Collection<string, CustomField>
     */
    private function resolveAllFields(): Collection
    {
        return once(fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->currentTeam->getKey())
            ->where('entity_type', $this->entityType)
            ->active()
            ->get()
            ->keyBy('code'));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter="sorts opportunities by custom field"
```

- [ ] **Step 5: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Filters/CustomFieldSort.php tests/Feature/Mcp/Filters/CustomFieldSortTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/Mcp/Filters/CustomFieldSort.php tests/Feature/Mcp/Filters/CustomFieldSortTest.php
git commit -m "feat: add CustomFieldSort for sorting by custom field values"
```

---

## Chunk 2: BaseListTool Refactor & List Action Integration

### Task 4: Create CustomFieldFilterSchema

**Files:**
- Create: `app/Mcp/Schema/CustomFieldFilterSchema.php`
- Reference: `app/Mcp/Resources/Concerns/ResolvesEntitySchema.php` (pattern for resolving custom fields)
- Reference: `app/Enums/CustomFields/OpportunityField.php`, `app/Enums/CustomFields/PeopleField.php`

- [ ] **Step 1: Create the schema builder class**

This class reads the team's active custom fields and generates a JSON-schema-compatible array describing the filter operators per field.

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Enums\CustomFieldType;

final readonly class CustomFieldFilterSchema
{
    private const array EXCLUDED_TYPES = [
        CustomFieldType::FILE_UPLOAD->value,
        CustomFieldType::RECORD->value,
        CustomFieldType::TEXTAREA->value,
        CustomFieldType::RICH_EDITOR->value,
        CustomFieldType::MARKDOWN_EDITOR->value,
    ];

    private const array NUMERIC_OPERATORS = ['eq', 'gt', 'gte', 'lt', 'lte'];
    private const array STRING_OPERATORS = ['eq', 'contains'];
    private const array CHOICE_OPERATORS = ['eq', 'in'];
    private const array BOOLEAN_OPERATORS = ['eq'];
    private const array MULTI_OPERATORS = ['has_any'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function build(User $user, string $entityType): array
    {
        $fields = $this->resolveFilterableFields($user, $entityType);
        $schema = [];

        foreach ($fields as $field) {
            $operators = $this->operatorsForType($field->type);

            if ($operators === []) {
                continue;
            }

            $schema[$field->code] = [
                'type' => 'object',
                'description' => $field->name,
                'properties' => $operators,
            ];
        }

        return $schema;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function operatorsForType(string $type): array
    {
        $fieldType = CustomFieldType::tryFrom($type);

        if ($fieldType === null) {
            return [];
        }

        return match ($fieldType) {
            CustomFieldType::TEXT, CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK
                => $this->buildOperators(self::STRING_OPERATORS, 'string'),
            CustomFieldType::CURRENCY
                => $this->buildOperators(self::NUMERIC_OPERATORS, 'number'),
            CustomFieldType::NUMBER
                => $this->buildOperators(self::NUMERIC_OPERATORS, 'integer'),
            CustomFieldType::DATE
                => $this->buildOperators(self::NUMERIC_OPERATORS, 'string'),
            CustomFieldType::DATE_TIME
                => $this->buildOperators(self::NUMERIC_OPERATORS, 'string'),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE
                => $this->buildOperators(self::BOOLEAN_OPERATORS, 'boolean'),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS
                => array_merge(
                    $this->buildOperators(['eq'], 'string'),
                    ['in' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ),
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT
                => $this->buildOperators(self::MULTI_OPERATORS, 'string'),
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, string>>
     */
    private function buildOperators(array $operators, string $jsonType): array
    {
        $result = [];

        foreach ($operators as $op) {
            $result[$op] = ['type' => $jsonType];
        }

        return $result;
    }

    /**
     * Build AllowedSort entries for all filterable custom fields.
     *
     * @return array<int, \Spatie\QueryBuilder\AllowedSort>
     */
    public function allowedSorts(User $user, string $entityType): array
    {
        return collect(array_keys($this->build($user, $entityType)))
            ->map(fn (string $code): \Spatie\QueryBuilder\AllowedSort => \Spatie\QueryBuilder\AllowedSort::custom(
                $code,
                new \App\Mcp\Filters\CustomFieldSort($entityType),
            ))
            ->all();
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function resolveFilterableFields(User $user, string $entityType): Collection
    {
        $teamId = $user->currentTeam->getKey();
        $cacheKey = "custom_fields_filter_schema_{$teamId}_{$entityType}";

        return Cache::remember($cacheKey, 60, fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->where(fn ($q) => $q->whereNull('settings->encrypted')->orWhere('settings->encrypted', false))
            ->active()
            ->select('code', 'name', 'type')
            ->get());
    }
}
```

- [ ] **Step 2: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Schema/CustomFieldFilterSchema.php
```

- [ ] **Step 3: Commit**

```bash
git add app/Mcp/Schema/CustomFieldFilterSchema.php
git commit -m "feat: add CustomFieldFilterSchema for dynamic filter schema generation"
```

---

### Task 5: Refactor BaseListTool to support filter, sort, include

**Files:**
- Modify: `app/Mcp/Tools/BaseListTool.php`
- Test: `tests/Feature/Mcp/OpportunityToolsTest.php` (extend existing)

This is the core integration task. BaseListTool.handle() must:
1. Build a full `Request` object with filter/sort/include
2. Pass it as `$request` to the action (instead of `$filters` array)
3. Add filter/sort/include to the schema dynamically

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Feature/Mcp/OpportunityToolsTest.php`:

```php
it('can filter opportunities by custom field via MCP tool', function (): void {
    $opp1 = Opportunity::factory()->for($this->team)->create(['name' => 'Big Deal']);
    $opp2 = Opportunity::factory()->for($this->team)->create(['name' => 'Small Deal']);

    $amountField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    if ($amountField) {
        $opp1->saveCustomFieldValue($amountField, 100000);
        $opp2->saveCustomFieldValue($amountField, 5000);
    }

    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'amount' => ['gte' => 50000],
            ],
        ]);

    $response->assertOk()
        ->assertSee('Big Deal')
        ->assertDontSee('Small Deal');
});

it('can include relationships via MCP tool', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
    Opportunity::factory()->for($this->team)->create([
        'name' => 'Acme Deal',
        'company_id' => $company->id,
    ]);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'include' => ['company'],
        ]);

    $response->assertOk()
        ->assertSee('Acme Deal')
        ->assertSee('Acme Corp');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="can filter opportunities by custom field via MCP|can include relationships via MCP"
```

- [ ] **Step 3: Refactor BaseListTool**

Replace the full contents of `app/Mcp/Tools/BaseListTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class BaseListTool extends Tool
{
    use ChecksTokenAbility;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function searchFilterName(): string;

    /**
     * @return array<string, mixed>
     */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalFilters(Request $request): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge(
            ['search' => $schema->string()->description("Search by {$this->searchFilterName()}.")],
            $this->additionalSchema($schema),
            [
                'filter' => $schema->object()->description('Filter by custom field values. Keys are field codes, values are operator objects (eq, gt, gte, lt, lte, contains, in, has_any).'),
                'sort' => $schema->object()->description('Sort by field. Properties: field (string), direction (asc|desc).'),
                'include' => $schema->array()->description('Related records to expand in response.'),
                'per_page' => $schema->integer()->description('Results per page (default 15, max 100).')->default(15),
                'page' => $schema->integer()->description('Page number.')->default(1),
            ],
        );
    }

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('read');

        /** @var User $user */
        $user = auth()->user();

        $httpRequest = $this->buildHttpRequest($request);

        $action = app()->make($this->actionClass());
        $results = $action->execute(
            user: $user,
            perPage: (int) $request->get('per_page', 15),
            page: $request->get('page') ? (int) $request->get('page') : null,
            request: $httpRequest,
        );

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        return Response::text(
            $resourceClass::collection($results)->toJson(JSON_PRETTY_PRINT)
        );
    }

    private function buildHttpRequest(Request $mcpRequest): HttpRequest
    {
        $input = [];

        // Native filters (search + entity-specific like company_id)
        $nativeFilters = array_filter(array_merge(
            [$this->searchFilterName() => $mcpRequest->get('search')],
            $this->additionalFilters($mcpRequest),
        ));

        if ($nativeFilters !== []) {
            $input['filter'] = $nativeFilters;
        }

        // Custom field filters
        $customFieldFilters = $mcpRequest->get('filter');

        if (is_array($customFieldFilters) && $customFieldFilters !== []) {
            $input['filter']['custom_fields'] = $customFieldFilters;
        }

        // Sort: convert {"field": "amount", "direction": "desc"} to "-amount"
        $sort = $mcpRequest->get('sort');

        if (is_array($sort) && isset($sort['field'])) {
            $direction = ($sort['direction'] ?? 'asc') === 'desc' ? '-' : '';
            $input['sort'] = $direction . $sort['field'];
        }

        // Include: convert ["company", "contact"] to "company,contact"
        $include = $mcpRequest->get('include');

        if (is_array($include) && $include !== []) {
            $input['include'] = implode(',', $include);
        }

        return new HttpRequest($input);
    }
}
```

- [ ] **Step 4: Update ListOpportunities action to accept custom field filter + sorts**

Modify `app/Actions/Opportunity/ListOpportunities.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListOpportunities
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Opportunity>|LengthAwarePaginator<int, Opportunity>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', Opportunity::class), 403);

        $perPage = max(1, min($perPage, 100));

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::exact('company_id'),
                AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
            ])
            ->allowedFields(['id', 'name', 'company_id', 'contact_id', 'creator_id', 'created_at', 'updated_at'])
            ->allowedIncludes(['creator', 'company', 'contact'])
            ->allowedSorts([
                'name', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'opportunity'),
            ])
            ->defaultSort('-created_at');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
```

- [ ] **Step 5: Run the integration tests**

```bash
php artisan test --compact --filter="can filter opportunities by custom field via MCP|can include relationships via MCP"
```

Expected: Both pass.

- [ ] **Step 6: Run existing MCP tests to verify no regression**

```bash
php artisan test --compact tests/Feature/Mcp/OpportunityToolsTest.php
```

Expected: All existing tests still pass.

- [ ] **Step 7: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Tools/BaseListTool.php app/Actions/Opportunity/ListOpportunities.php
```

- [ ] **Step 8: Commit**

```bash
git add app/Mcp/Tools/BaseListTool.php app/Actions/Opportunity/ListOpportunities.php tests/Feature/Mcp/OpportunityToolsTest.php
git commit -m "feat: add custom field filtering and includes to BaseListTool and ListOpportunities"
```

---

### Task 6: Update remaining 4 List actions

**Files:**
- Modify: `app/Actions/People/ListPeople.php`
- Modify: `app/Actions/Company/ListCompanies.php`
- Modify: `app/Actions/Task/ListTasks.php`
- Modify: `app/Actions/Note/ListNotes.php`

Each action gets the same change: add `AllowedFilter::custom('custom_fields', new CustomFieldFilter($entityType))` and `...$filterSchema->allowedSorts($user, $entityType)`. Use the shared `CustomFieldFilterSchema::allowedSorts()` method -- no code duplication.

- [ ] **Step 1: Update ListPeople**

Add `use App\Mcp\Filters\CustomFieldFilter;` and `use App\Mcp\Schema\CustomFieldFilterSchema;`. Create `$filterSchema = new CustomFieldFilterSchema;` before QueryBuilder. Add to allowedFilters: `AllowedFilter::custom('custom_fields', new CustomFieldFilter('people'))`. Add to allowedSorts: `...$filterSchema->allowedSorts($user, 'people')`.

- [ ] **Step 2: Update ListCompanies**

Same pattern with entity type `'company'`.

- [ ] **Step 3: Update ListTasks**

Same pattern with entity type `'task'`.

- [ ] **Step 4: Update ListNotes**

Same pattern with entity type `'note'`.

- [ ] **Step 5: Run all MCP tests**

```bash
php artisan test --compact tests/Feature/Mcp/
```

Expected: All tests pass.

- [ ] **Step 6: Run pint + phpstan on all modified actions**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Actions/People/ListPeople.php app/Actions/Company/ListCompanies.php app/Actions/Task/ListTasks.php app/Actions/Note/ListNotes.php
```

- [ ] **Step 7: Commit**

```bash
git add app/Actions/People/ListPeople.php app/Actions/Company/ListCompanies.php app/Actions/Task/ListTasks.php app/Actions/Note/ListNotes.php
git commit -m "feat: add custom field filtering and sorting to all List actions"
```

---

## Chunk 3: CRM Summary Resource & Final Integration

### Task 7: Create CrmSummaryResource

**Files:**
- Create: `app/Mcp/Resources/CrmSummaryResource.php`
- Create: `tests/Feature/Mcp/CrmSummaryResourceTest.php`
- Modify: `app/Mcp/Servers/RelaticleServer.php` (register resource)
- Reference: `app/Enums/CustomFields/OpportunityField.php` (field codes)
- Reference: `app/Enums/CustomFields/TaskField.php` (field codes)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Mcp\Resources\CrmSummaryResource;
use App\Mcp\Servers\RelaticleServer;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('returns CRM summary with record counts', function (): void {
    Company::factory()->for($this->team)->count(3)->create();
    People::factory()->for($this->team)->count(5)->create();
    Opportunity::factory()->for($this->team)->count(2)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->resource(CrmSummaryResource::class);

    $response->assertOk()
        ->assertSee('"total":3')   // companies
        ->assertSee('"total":5')   // people
        ->assertSee('"total":2');  // opportunities
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="returns CRM summary"
```

- [ ] **Step 3: Implement CrmSummaryResource**

Create `app/Mcp/Resources/CrmSummaryResource.php`. Use the SQL queries from the spec, resolving field IDs via enum codes. Cache for 60 seconds.

Key implementation points:
- Resolve `OpportunityField::Stage->value` and `OpportunityField::Amount->value` from custom_fields table
- Resolve `TaskField::DUE_DATE->value` from custom_fields table
- Use morph aliases (`'opportunity'`, `'task'`) not FQCNs
- Use `datetime_value::date` for task due date comparison
- Fall back gracefully if custom fields don't exist

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter="returns CRM summary"
```

- [ ] **Step 5: Register in RelaticleServer**

Add `CrmSummaryResource::class` to the `$resources` array in `app/Mcp/Servers/RelaticleServer.php:72-78`.

- [ ] **Step 6: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Resources/CrmSummaryResource.php app/Mcp/Servers/RelaticleServer.php
```

- [ ] **Step 7: Commit**

```bash
git add app/Mcp/Resources/CrmSummaryResource.php tests/Feature/Mcp/CrmSummaryResourceTest.php app/Mcp/Servers/RelaticleServer.php
git commit -m "feat: add CRM summary aggregation resource"
```

---

### Task 8: Full integration test & quality checks

**Files:**
- Test: `tests/Feature/Mcp/OpportunityToolsTest.php` (add combined filter+sort+include test)

- [ ] **Step 1: Write end-to-end integration test**

```php
it('can filter, sort, and include in one request via MCP', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $opp1 = Opportunity::factory()->for($this->team)->create([
        'name' => 'Big Deal',
        'company_id' => $company->id,
    ]);
    $opp2 = Opportunity::factory()->for($this->team)->create([
        'name' => 'Small Deal',
        'company_id' => $company->id,
    ]);

    $amountField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    if ($amountField) {
        $opp1->saveCustomFieldValue($amountField, 100000);
        $opp2->saveCustomFieldValue($amountField, 5000);
    }

    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => ['amount' => ['gte' => 50000]],
            'sort' => ['field' => 'amount', 'direction' => 'desc'],
            'include' => ['company'],
        ]);

    $response->assertOk()
        ->assertSee('Big Deal')
        ->assertDontSee('Small Deal')
        ->assertSee('Acme Corp');
});
```

- [ ] **Step 2: Run the full test**

```bash
php artisan test --compact --filter="can filter, sort, and include in one request"
```

- [ ] **Step 3: Run the complete MCP test suite**

```bash
php artisan test --compact tests/Feature/Mcp/
```

Expected: All tests pass, including existing ones (backwards compatibility).

- [ ] **Step 4: Run full quality checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Mcp/OpportunityToolsTest.php
git commit -m "test: add end-to-end integration test for filter+sort+include"
```

- [ ] **Step 6: Push and check CI**

```bash
git push -u origin HEAD
gh run list --branch $(git branch --show-current) -L 1
```

---

### Task 9: Update schema resources to document filter capabilities

**Files:**
- Modify: `app/Mcp/Resources/OpportunitySchemaResource.php`
- Modify: `app/Mcp/Resources/PeopleSchemaResource.php`
- Modify: `app/Mcp/Resources/CompanySchemaResource.php`
- Modify: `app/Mcp/Resources/TaskSchemaResource.php`
- Modify: `app/Mcp/Resources/NoteSchemaResource.php`

- [ ] **Step 1: Add filterable fields info to each schema resource**

In each schema resource's `content()` method, add a `filterable_fields` section that lists which fields can be filtered and their supported operators. Use `CustomFieldFilterSchema::build()` to generate this dynamically.

- [ ] **Step 2: Run existing schema resource tests**

```bash
php artisan test --compact tests/Feature/Mcp/SchemaResourcesTest.php
```

- [ ] **Step 3: Run pint + phpstan**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Mcp/Resources/
```

- [ ] **Step 4: Commit**

```bash
git add app/Mcp/Resources/
git commit -m "feat: document filterable fields in MCP schema resources"
```

---

### Task 10: Update hero conversation to use real field codes

**Files:**
- Modify: `resources/views/home/partials/hero-agent-preview.blade.php`

- [ ] **Step 1: Verify default custom field codes**

Check `app/Enums/CustomFields/OpportunityField.php` and `app/Enums/CustomFields/PeopleField.php` to confirm exact field codes used in the hero conversation (stage, amount, close_date, job_title) exist as default team custom fields.

- [ ] **Step 2: Update hero conversation if field codes don't match**

Ensure the hero preview accurately represents what the MCP tools can now do with the new filtering capabilities.

- [ ] **Step 3: Commit**

```bash
git add resources/views/home/partials/hero-agent-preview.blade.php
git commit -m "fix: update hero conversation to reflect actual MCP capabilities"
```
