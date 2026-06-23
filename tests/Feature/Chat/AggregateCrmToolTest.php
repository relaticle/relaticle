<?php

declare(strict_types=1);

use App\Actions\Opportunity\AggregateOpportunities;
use App\Actions\Opportunity\CreateOpportunity;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Tools\AggregateCrmTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(AggregateCrmTool::class, AggregateOpportunities::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
    TenantContextService::setTenantId($this->team->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('groups opportunities by stage with correct count and total_amount', function (): void {
    $createOpportunity = resolve(CreateOpportunity::class);

    // Resolve stage field and its first two options
    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->with('options')
        ->firstOrFail();

    $stageOptions = $stageField->options;
    $stage1Option = $stageOptions->first();
    $stage2Option = $stageOptions->skip(1)->first();

    // Create 2 opportunities in stage 1 (amounts: 1000 + 2500 = 3500)
    // Pass option ID (ULID) directly — CreateOpportunity stores the raw string_value
    $createOpportunity->execute($this->user, [
        'name' => 'Deal Alpha',
        'custom_fields' => [
            'stage' => (string) $stage1Option->getKey(),
            'amount' => 1000,
        ],
    ]);
    $createOpportunity->execute($this->user, [
        'name' => 'Deal Beta',
        'custom_fields' => [
            'stage' => (string) $stage1Option->getKey(),
            'amount' => 2500,
        ],
    ]);

    // Create 1 opportunity in stage 2 (amount: 500)
    $createOpportunity->execute($this->user, [
        'name' => 'Deal Gamma',
        'custom_fields' => [
            'stage' => (string) $stage2Option->getKey(),
            'amount' => 500,
        ],
    ]);

    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'stage']));

    $data = json_decode($response, true);
    expect($data)->toBeArray()
        ->and($data['group_by'])->toBe('stage')
        ->and($data['total_count'])->toBe(3)
        ->and((float) $data['total_amount'])->toBe(4000.0);

    // Find stage 1 and stage 2 rows
    $rowsByLabel = collect($data['rows'])->keyBy('label');

    expect($rowsByLabel->has($stage1Option->name))->toBeTrue()
        ->and($rowsByLabel->has($stage2Option->name))->toBeTrue();

    $stage1Row = $rowsByLabel->get($stage1Option->name);
    $stage2Row = $rowsByLabel->get($stage2Option->name);

    expect($stage1Row['count'])->toBe(2)
        ->and((float) $stage1Row['total_amount'])->toBe(3500.0)
        ->and($stage2Row['count'])->toBe(1)
        ->and((float) $stage2Row['total_amount'])->toBe(500.0);
});

it('groups opportunities by company with correct aggregation', function (): void {
    $createOpportunity = resolve(CreateOpportunity::class);

    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $createOpportunity->execute($this->user, [
        'name' => 'Deal 1',
        'company_id' => (string) $company->getKey(),
        'custom_fields' => ['amount' => 3000],
    ]);
    $createOpportunity->execute($this->user, [
        'name' => 'Deal 2',
        'company_id' => (string) $company->getKey(),
        'custom_fields' => ['amount' => 2000],
    ]);
    $createOpportunity->execute($this->user, [
        'name' => 'No company deal',
        'custom_fields' => ['amount' => 100],
    ]);

    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'company']));

    $data = json_decode($response, true);
    expect($data)->toBeArray()
        ->and($data['group_by'])->toBe('company')
        ->and($data['total_count'])->toBe(3)
        ->and((float) $data['total_amount'])->toBe(5100.0);

    $rowsByLabel = collect($data['rows'])->keyBy('label');
    expect($rowsByLabel->has('Acme Corp'))->toBeTrue();

    $acmeRow = $rowsByLabel->get('Acme Corp');
    expect($acmeRow['count'])->toBe(2)
        ->and((float) $acmeRow['total_amount'])->toBe(5000.0);
});

it('applies date_from filter correctly', function (): void {
    $createOpportunity = resolve(CreateOpportunity::class);

    $this->travelTo(now()->subDays(10));
    $createOpportunity->execute($this->user, [
        'name' => 'Old Deal',
        'custom_fields' => ['amount' => 999],
    ]);

    $this->travelBack();
    $createOpportunity->execute($this->user, [
        'name' => 'New Deal',
        'custom_fields' => ['amount' => 1000],
    ]);

    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request([
        'group_by' => 'company',
        'date_from' => now()->subDays(1)->toDateString(),
    ]));

    $data = json_decode($response, true);
    expect($data['total_count'])->toBe(1)
        ->and((float) $data['total_amount'])->toBe(1000.0);
});

it('reports accurate grand totals when company groups exceed the row cap', function (): void {
    Company::factory()->count(101)->for($this->team)->create()->each(function (Company $company): void {
        Opportunity::factory()->for($this->team)->create(['company_id' => $company->getKey()]);
    });

    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'company']));

    $data = json_decode($response, true);

    expect($data['total_count'])->toBe(101)
        ->and($data['rows'])->toHaveCount(100)
        ->and($data['truncated'])->toBeTrue();
});

it('returns an error for invalid group_by value', function (): void {
    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'invalid']));

    $data = json_decode($response, true);
    expect($data)->toBeArray()
        ->and($data)->toHaveKey('error');
});

it('returns grand total of zero when no opportunities exist', function (): void {
    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'stage']));

    $data = json_decode($response, true);
    expect($data['total_count'])->toBe(0)
        ->and((float) $data['total_amount'])->toBe(0.0);
});

it('respects tenant scope and does not leak cross-tenant data', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    TenantContextService::setTenantId($otherTeam->getKey());
    Auth::guard('web')->setUser($otherUser);
    resolve(CreateOpportunity::class)->execute($otherUser, [
        'name' => 'Other Team Deal',
        'custom_fields' => ['amount' => 99999],
    ]);

    TenantContextService::setTenantId($this->team->getKey());
    Auth::guard('web')->setUser($this->user);

    $tool = resolve(AggregateCrmTool::class);
    $response = $tool->handle(new Request(['group_by' => 'company']));

    $data = json_decode($response, true);
    expect($data['total_count'])->toBe(0)
        ->and((float) $data['total_amount'])->toBe(0.0);
});
