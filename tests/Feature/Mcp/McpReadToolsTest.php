<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Actions\Crm\GetCrmSummary;
use App\Actions\Opportunity\AggregateOpportunities;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\AggregateOpportunitiesTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\GetCrmSummaryTool;
use App\Mcp\Tools\ListActivityTool;
use App\Mcp\Tools\ListCustomFieldsTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(
    AggregateOpportunities::class,
    AggregateOpportunitiesTool::class,
    BaseListTool::class,
    GetCrmSummary::class,
    GetCrmSchemaTool::class,
    GetCrmSummaryTool::class,
    ListActivityTool::class,
    ListCompaniesTool::class,
    ListCustomFieldsTool::class,
    ListNotesTool::class,
    ListOpportunitiesTool::class,
    ListPeopleTool::class,
    ListTasksTool::class,
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
    $option = $field->options->sortBy('sort_order')->firstOrFail();

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
            ->where('items.0.options.0.id', $option->getKey())
            ->where('items.0.options.0.label', $option->name)
            ->where('has_more', false)
            ->where('next_page', null)
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

it('marks aggregates truncated only when more than one hundred groups exist', function (): void {
    $companies = Company::factory()
        ->count(100)
        ->recycle([$this->user, $this->team])
        ->create();

    foreach ($companies as $company) {
        Opportunity::factory()->recycle([$this->user, $this->team])->create([
            'company_id' => $company->getKey(),
        ]);
    }

    RelaticleServer::actingAs($this->user)
        ->tool(AggregateOpportunitiesTool::class, ['group_by' => 'company'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('rows', 100)
            ->where('total_count', 100)
            ->where('truncated', false)
            ->etc());

    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    Opportunity::factory()->recycle([$this->user, $this->team])->create([
        'company_id' => $company->getKey(),
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(AggregateOpportunitiesTool::class, ['group_by' => 'company'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('rows', 100)
            ->where('total_count', 101)
            ->where('truncated', true)
            ->etc());
});

it('returns activity with complete saves and caller-timezone timestamps', function (): void {
    $this->user->update(['timezone' => 'Asia/Yerevan']);
    $company = Company::withoutEvents(fn (): Company => Company::factory()
        ->recycle([$this->user, $this->team])
        ->create(['name' => 'Before']));

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
            ->where('items.0.record.type', 'company')
            ->where('items.0.record.url', fn (string $url): bool => str_contains($url, (string) $company->getKey()))
            ->where('items.0.by', $this->user->name)
            ->where('items.0.event', 'updated')
            ->where('items.0.at', fn (string $at): bool => str_ends_with($at, '+04:00'))
            ->where('items.0.changes.0.field', 'Name')
            ->where('items.0.changes.0.old', 'Before')
            ->where('items.0.changes.0.new', 'After')
            ->has('total')
            ->where('has_more', false)
            ->where('next_page', null)
            ->etc());
});

it('denies activity reads to an unverified user', function (): void {
    $unverifiedUser = User::factory()->withPersonalTeam()->unverified()->create();

    RelaticleServer::actingAs($unverifiedUser)
        ->tool(ListActivityTool::class)
        ->assertHasErrors(['permission']);
});

it('requires an activity record type when a record ID is provided', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, ['record_id' => '01K00000000000000000000000'])
        ->assertHasErrors(['record type']);
});

it('rejects page numbers that could overflow database offsets', function (string $toolClass): void {
    RelaticleServer::actingAs($this->user)
        ->tool($toolClass, ['page' => PHP_INT_MAX])
        ->assertHasErrors(['page']);
})->with([
    ListCompaniesTool::class,
    ListActivityTool::class,
    ListCustomFieldsTool::class,
]);

it('publishes the opportunity stale day bounds', function (): void {
    expect(resolve(ListOpportunitiesTool::class)->toArray())
        ->toHaveKey('inputSchema.properties.stale_days.minimum', 1)
        ->toHaveKey('inputSchema.properties.stale_days.maximum', 3650);
});

it('rejects malformed list tool inputs before building the database query', function (string $toolClass, array $input, string $error): void {
    RelaticleServer::actingAs($this->user)
        ->tool($toolClass, $input)
        ->assertHasErrors([$error]);
})->with([
    'search' => [ListCompaniesTool::class, ['search' => ['Acme']], 'search'],
    'created after' => [ListCompaniesTool::class, ['created_after' => 'yesterday'], 'created after'],
    'created before' => [ListCompaniesTool::class, ['created_before' => '26-08-2026'], 'created before'],
    'date range' => [ListCompaniesTool::class, ['created_after' => '2026-08-27', 'created_before' => '2026-08-26'], 'created before'],
    'filter object' => [ListCompaniesTool::class, ['filter' => ['invalid']], 'filter field must be an object'],
    'filter operator object' => [ListCompaniesTool::class, ['filter' => ['industry' => 'software']], 'filter.industry'],
    'sort object' => [ListCompaniesTool::class, ['sort' => 'name'], 'sort'],
    'sort field' => [ListCompaniesTool::class, ['sort' => ['direction' => 'asc']], 'field'],
    'sort direction' => [ListCompaniesTool::class, ['sort' => ['field' => 'name', 'direction' => 'sideways']], 'sort.direction'],
    'include list' => [ListCompaniesTool::class, ['include' => ['primary' => 'creator']], 'include'],
    'people company id' => [ListPeopleTool::class, ['company_id' => []], 'company id'],
    'opportunity company id' => [ListOpportunitiesTool::class, ['company_id' => []], 'company id'],
    'opportunity contact id' => [ListOpportunitiesTool::class, ['contact_id' => []], 'contact id'],
    'opportunity stale days minimum' => [ListOpportunitiesTool::class, ['stale_days' => 0], 'stale days'],
    'opportunity stale days maximum' => [ListOpportunitiesTool::class, ['stale_days' => 3651], 'stale days'],
    'task assigned to me' => [ListTasksTool::class, ['assigned_to_me' => 'yes'], 'assigned to me'],
    'task assignee ids' => [ListTasksTool::class, ['assignee_ids' => 'user-id'], 'assignee ids'],
    'task assignee id' => [ListTasksTool::class, ['assignee_ids' => ['not-a-ulid']], 'assignee_ids.0'],
    'task company id' => [ListTasksTool::class, ['company_id' => []], 'company id'],
    'task people id' => [ListTasksTool::class, ['people_id' => []], 'people id'],
    'task opportunity id' => [ListTasksTool::class, ['opportunity_id' => []], 'opportunity id'],
    'note notable type' => [ListNotesTool::class, ['notable_type' => 'deal'], 'notable type'],
    'note notable id' => [ListNotesTool::class, ['notable_id' => []], 'notable id'],
]);

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

it('reports each stage separately so a caller can decide what counts as won', function (): void {
    $stage = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();
    $closedWon = $stage->options()->withoutGlobalScopes()->where('name', 'Closed Won')->firstOrFail();
    $unwon = CustomFieldOption::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $stage->getKey(),
        'name' => 'Unwon',
        'sort_order' => 99,
    ]);
    $wonOpportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
    $unwonOpportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $wonOpportunity->saveCustomFieldValue($stage, $closedWon->getKey());
    $wonOpportunity->saveCustomFieldValue($amount, 100);
    $unwonOpportunity->saveCustomFieldValue($stage, $unwon->getKey());
    $unwonOpportunity->saveCustomFieldValue($amount, 500);

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSummaryTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('opportunities.total_pipeline_value', 600)
            ->where('opportunities.by_stage.Closed Won.total_amount', 100)
            ->where('opportunities.by_stage.Unwon.total_amount', 500)
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
