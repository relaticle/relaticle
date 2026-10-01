<?php

declare(strict_types=1);

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Spatie\QueryBuilder\QueryBuilder;

mutates(
    BaseListTool::class,
    CustomFieldFilter::class,
    CustomFieldFilterSchema::class,
    GetCrmSchemaTool::class,
    ListOpportunitiesTool::class,
    ListPeopleTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->actingAs($this->user);
    resolve(CurrentWorkspace::class)->set($this->workspace);
});

function filterTestField(Workspace $workspace, string $entityType, string $code, string $type, ?CustomFieldSettingsData $settings = null): CustomField
{
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => $entityType,
        'name' => "{$code} section",
        'code' => "{$code}_section",
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);

    return CustomField::query()->create([
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

it('filters by custom field equality', function (): void {
    $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Deal A']);
    $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Deal B']);

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->first();

    expect($stageField)->not->toBeNull('Stage custom field must exist for this test');

    $opportunity1->saveCustomFieldValue($stageField, 'Proposal');
    $opportunity2->saveCustomFieldValue($stageField, 'Prospecting');

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'stage' => ['eq' => 'Proposal'],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Deal A');
});

it('filters by currency field with gte operator', function (): void {
    $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big Deal']);
    $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Small Deal']);

    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    expect($amountField)->not->toBeNull('Amount custom field must exist for this test');

    $opportunity1->saveCustomFieldValue($amountField, 100000);
    $opportunity2->saveCustomFieldValue($amountField, 5000);

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'amount' => ['gte' => 50000],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Big Deal');
});

it('rejects unknown field codes', function (): void {
    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'nonexistent_field' => ['eq' => 'test'],
            ],
        ],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class, '"nonexistent_field" is not a filterable custom field on opportunity.');

it('names one unknown field code at a time', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'first_typo' => ['eq' => 'x'],
                'second_typo' => ['eq' => 'y'],
            ],
        ])
        ->assertHasErrors(['"first_typo" is not a filterable custom field on opportunity.']);
});

it('rejects unknown operators', function (): void {
    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                $amountField->code => ['approximately' => 50000],
            ],
        ],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class, 'Operator "approximately" is not supported for "amount".');

it('rejects more than 10 filter conditions', function (): void {
    $filters = [];

    for ($i = 0; $i < 11; $i++) {
        $filters["field_{$i}"] = ['eq' => 'test'];
    }

    $request = new Request([
        'filter' => ['custom_fields' => $filters],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class);

it('returns an actionable MCP error for an operator incompatible with the field type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'amount' => ['contains' => '500'],
            ],
        ])
        ->assertHasErrors(['Operator "contains" is not supported for "amount".']);
});

it('rejects an encrypted custom field as an unknown filter code', function (): void {
    filterTestField($this->workspace, 'opportunity', 'secret_code', 'text', new CustomFieldSettingsData(encrypted: true));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => ['secret_code' => ['eq' => 'x']],
        ])
        ->assertHasErrors(['"secret_code" is not a filterable custom field on opportunity']);
});

it('returns an actionable MCP error for an invalid operand shape', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'stage' => ['in' => ['nested' => 'Qualification']],
            ],
        ])
        ->assertHasErrors(['must be an array']);
});

it('returns an actionable MCP error for an operand that is not the declared type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'amount' => ['gt' => 'lots'],
            ],
        ])
        ->assertHasErrors(['must be a number']);
});

it('accepts a single value for an array operand', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Proposed Deal']);

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();

    $opportunity->saveCustomFieldValue($stageField, 'Qualification');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'stage' => ['in' => 'Qualification'],
            ],
        ])
        ->assertOk()
        ->assertSee('Qualified Deal')
        ->assertDontSee('Proposed Deal');
});

it('publishes only array-compatible operators for email, phone, and link fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'people'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('filterable_fields.emails.properties', ['has_any' => ['type' => 'string']])
            ->where('filterable_fields.phone_number.properties', ['has_any' => ['type' => 'string']])
            ->where('filterable_fields.linkedin.properties', ['has_any' => ['type' => 'string']])
            ->etc());
});

it('filters json array custom fields through the people list tool', function (string $fieldCode, mixed $matchingValue, mixed $otherValue, string $operand): void {
    $matchingPerson = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Matching Person']);
    $otherPerson = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Other Person']);
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', $fieldCode)
        ->firstOrFail();

    $matchingPerson->saveCustomFieldValue($field, $matchingValue);
    $otherPerson->saveCustomFieldValue($field, $otherValue);

    RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class, [
            'filter' => [
                $fieldCode => ['has_any' => $operand],
            ],
        ])
        ->assertOk()
        ->assertSee('Matching Person')
        ->assertDontSee('Other Person');
})->with([
    'email' => ['emails', ['match@example.com'], ['other@example.com'], 'match@example.com'],
    'phone' => ['phone_number', '+15550000001', '+15550000002', '+15550000001'],
    'link' => ['linkedin', 'https://example.com/match', 'https://example.com/other', 'https://example.com/match'],
]);

it('handles empty filter object as no-op', function (): void {
    $countBefore = Opportunity::query()->count();

    Opportunity::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    $request = new Request([
        'filter' => [
            'custom_fields' => [],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();

    expect($results)->toHaveCount($countBefore + 3);
});
