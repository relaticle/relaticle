<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\AggregateOpportunitiesTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\GetCrmSummaryTool;
use App\Mcp\Tools\ListActivityTool;
use App\Mcp\Tools\ListCustomFieldsTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(
    AggregateOpportunitiesTool::class,
    GetCrmSchemaTool::class,
    GetCrmSummaryTool::class,
    ListActivityTool::class,
    ListCustomFieldsTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('returns the current active schema through a tool', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'people'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('entity', 'people')
            ->has('custom_fields.emails')
            ->has('filterable_fields')
            ->has('relationships')
            ->etc());
});

it('lists inactive custom fields and their option labels', function (): void {
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->with('options')
        ->firstOrFail();
    $field->update(['active' => false]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListCustomFieldsTool::class, [
            'entity_type' => 'opportunity',
            'active' => false,
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('items', 1)
            ->where('items.0.code', 'stage')
            ->where('items.0.active', false)
            ->has('items.0.options')
            ->where('has_more', false)
            ->etc());
});

it('aggregates opportunity counts and amount by company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme']);
    $opportunities = Opportunity::factory()->count(2)->recycle([$this->user, $this->team])->create([
        'company_id' => $company->id,
    ]);
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();
    $opportunities[0]->saveCustomFieldValue($amount, 100);
    $opportunities[1]->saveCustomFieldValue($amount, 200);

    RelaticleServer::actingAs($this->user)
        ->tool(AggregateOpportunitiesTool::class, ['group_by' => 'company'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('group_by', 'company')
            ->where('rows.0.label', 'Acme')
            ->where('rows.0.count', 2)
            ->where('rows.0.total_amount', 300)
            ->where('total_count', 2)
            ->etc());
});

it('returns activity with complete saves and caller-timezone timestamps', function (): void {
    $this->user->update(['timezone' => 'Asia/Yerevan']);
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Before']);

    $this->actingAs($this->user);
    resolve(UpdateCompany::class)->execute($this->user, $company, ['name' => 'After']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, [
            'record_type' => 'company',
            'record_id' => $company->id,
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('items')
            ->where('items.0.record.id', $company->id)
            ->where('items.0.record.name', 'After')
            ->where('items.0.at', fn (string $at): bool => str_ends_with($at, '+04:00'))
            ->has('items.0.changes')
            ->has('total')
            ->etc());
});

it('computes task due status in the caller timezone', function (): void {
    $this->travelTo(Date::parse('2026-08-26 06:30:00 UTC'));
    $this->user->update(['timezone' => 'America/Los_Angeles']);
    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $dueDate = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->firstOrFail();
    $task->saveCustomFieldValue($dueDate, '2026-08-25 20:00:00');

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSummaryTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('as_of.date', '2026-08-25')
            ->where('as_of.timezone', 'America/Los_Angeles')
            ->where('tasks.overdue', 0)
            ->where('tasks.due_this_week', 1)
            ->etc());
});

it('keeps custom-field definition reads scoped to the current team', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $otherField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $other->currentTeam->getKey())
        ->firstOrFail();

    RelaticleServer::actingAs($this->user)
        ->tool(ListCustomFieldsTool::class)
        ->assertOk()
        ->assertDontSee($otherField->id);
});
