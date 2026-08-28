<?php

declare(strict_types=1);

use App\Actions\Crm\GetCrmSummary;
use App\Actions\Opportunity\AggregateOpportunities;
use App\Mcp\Resources\CrmSummaryResource;
use App\Mcp\Servers\RelaticleServer;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

mutates(
    AggregateOpportunities::class,
    CrmSummaryResource::class,
    GetCrmSummary::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('returns CRM summary with record counts', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->count(3)->create();
    People::factory()->recycle([$this->user, $this->team])->count(5)->create();
    Opportunity::factory()->recycle([$this->user, $this->team])->count(2)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->resource(CrmSummaryResource::class);

    $response->assertOk()
        ->assertSee('"companies"')
        ->assertSee('"people"')
        ->assertSee('"opportunities"')
        ->assertSee('"tasks"')
        ->assertSee('"notes"');
});

it('includes opportunity pipeline breakdown', function (): void {
    $opp = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->first();

    if ($stageField) {
        $opp->saveCustomFieldValue($stageField, 'Proposal');
    }

    $response = RelaticleServer::actingAs($this->user)
        ->resource(CrmSummaryResource::class);

    $response->assertOk()
        ->assertSee('by_stage')
        ->assertSee('total_pipeline_value');
});

it('includes task overdue and due this week counts', function (): void {
    Task::factory()->recycle([$this->user, $this->team])->create();

    $response = RelaticleServer::actingAs($this->user)
        ->resource(CrmSummaryResource::class);

    $response->assertOk()
        ->assertSee('"overdue"')
        ->assertSee('"due_this_week"');
});

it('keeps unstaged and orphaned-stage opportunities in separate pipeline buckets', function (): void {
    $stageField = opportunityField($this->team, 'stage');
    $amountField = opportunityField($this->team, 'amount');

    $unstaged = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'No Stage']);
    $unstaged->saveCustomFieldValue($amountField, 1000);

    $orphaned = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Orphan Stage']);
    $orphaned->saveCustomFieldValue($amountField, 2000);
    $orphaned->saveCustomFieldValue($stageField, 'Proposal');

    $optionId = CustomFieldValue::query()->withoutGlobalScopes()
        ->where('entity_id', $orphaned->getKey())
        ->where('custom_field_id', $stageField->getKey())
        ->value('string_value');

    CustomFieldOption::query()->withoutGlobalScopes()->whereKey($optionId)->delete();

    $summary = app(GetCrmSummary::class)->execute($this->user)['opportunities'];

    expect($summary['truncated'])->toBeFalse()
        ->and($summary['by_stage'])->toHaveCount(2)
        ->and($summary['by_stage']['Unspecified']['total_amount'])->toBe(1000.0)
        ->and($summary['by_stage']["Unknown stage ({$optionId})"]['total_amount'])->toBe(2000.0)
        ->and(array_sum(array_column($summary['by_stage'], 'total_amount')))
        ->toBe($summary['total_pipeline_value']);
});

function opportunityField(Team $team, string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', $code)
        ->firstOrFail();
}
