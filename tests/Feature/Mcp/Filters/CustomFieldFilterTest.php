<?php

declare(strict_types=1);

use App\Mcp\Filters\CustomFieldFilter;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\Scopes\TeamScope;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    $opportunity1 = Opportunity::factory()->for($this->team)->create(['name' => 'Deal A']);
    $opportunity2 = Opportunity::factory()->for($this->team)->create(['name' => 'Deal B']);

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
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        )
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Deal A');
});

it('filters by currency field with gte operator', function (): void {
    $opportunity1 = Opportunity::factory()->for($this->team)->create(['name' => 'Big Deal']);
    $opportunity2 = Opportunity::factory()->for($this->team)->create(['name' => 'Small Deal']);

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
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        )
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Big Deal');
});

it('silently ignores unknown field codes', function (): void {
    $countBefore = Opportunity::query()->count();

    Opportunity::factory()->for($this->team)->create();

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'nonexistent_field' => ['eq' => 'test'],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        )
        ->get();

    expect($results)->toHaveCount($countBefore + 1);
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
        ->allowedFilters(
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        )
        ->get();
})->throws(HttpException::class);

it('handles empty filter object as no-op', function (): void {
    $countBefore = Opportunity::query()->count();

    Opportunity::factory()->for($this->team)->count(3)->create();

    $request = new Request([
        'filter' => [
            'custom_fields' => [],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            AllowedFilter::custom('custom_fields', new CustomFieldFilter('opportunity')),
        )
        ->get();

    expect($results)->toHaveCount($countBefore + 3);
});
