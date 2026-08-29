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
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\TeamScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
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
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->actingAs($this->user);
    Opportunity::addGlobalScope(new TeamScope);
});

afterEach(function (): void {
    Opportunity::clearBootedModels();
});

it('filters by custom field equality', function (): void {
    $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Deal A']);
    $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Deal B']);

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
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
    $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Big Deal']);
    $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Small Deal']);

    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
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
})->throws(ValidationException::class, 'Unknown custom field filter codes: nonexistent_field.');

it('rejects unknown operators', function (): void {
    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
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
})->throws(ValidationException::class, 'Custom field [amount] does not support operator [approximately].');

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
        ->assertHasErrors(['does not support operator [contains]']);
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
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Qualified Deal']);
    Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Proposed Deal']);

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
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
    $matchingPerson = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Matching Person']);
    $otherPerson = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Other Person']);
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
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

    Opportunity::factory()->recycle([$this->user, $this->team])->count(3)->create();

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
