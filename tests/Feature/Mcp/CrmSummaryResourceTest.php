<?php

declare(strict_types=1);

use App\Mcp\Resources\CrmSummaryResource;
use App\Mcp\Servers\RelaticleServer;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
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
        ->assertSee('"companies"')
        ->assertSee('"people"')
        ->assertSee('"opportunities"')
        ->assertSee('"tasks"')
        ->assertSee('"notes"');
});

it('includes opportunity pipeline breakdown', function (): void {
    $opp = Opportunity::factory()->for($this->team)->create();

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
    Task::factory()->for($this->team)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->resource(CrmSummaryResource::class);

    $response->assertOk()
        ->assertSee('"overdue"')
        ->assertSee('"due_this_week"');
});
