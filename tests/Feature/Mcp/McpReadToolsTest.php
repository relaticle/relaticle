<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Actions\Crm\GetCrmSummary;
use App\Actions\Opportunity\AggregateOpportunities;
use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
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
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentSource;
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
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
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
        ->where('tenant_id', $this->workspace->getKey())
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
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $opportunities = Opportunity::factory()->count(2)->recycle([$this->user, $this->workspace])->create([
        'company_id' => $company->id,
    ]);
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
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
        ->recycle([$this->user, $this->workspace])
        ->create();

    foreach ($companies as $company) {
        Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
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

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
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
        ->recycle([$this->user, $this->workspace])
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
    $unverifiedUser = User::factory()->withPersonalWorkspace()->unverified()->create();

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

it('publishes one filter object and no flat filter params on every list tool', function (string $toolClass): void {
    $properties = resolve($toolClass)->toArray()['inputSchema']['properties'];

    expect(array_keys($properties))->toBe(['filter', 'sort', 'include', 'per_page', 'page']);
})->with([
    ListCompaniesTool::class,
    ListPeopleTool::class,
    ListOpportunitiesTool::class,
    ListTasksTool::class,
    ListNotesTool::class,
]);

it('rejects malformed list tool inputs before building the database query', function (string $toolClass, array $input, string $error): void {
    RelaticleServer::actingAs($this->user)
        ->tool($toolClass, $input)
        ->assertHasErrors([$error]);
})->with([
    'filter date operand' => [ListCompaniesTool::class, ['filter' => ['created_at' => ['$gte' => 'yesterday']]], 'created_at $gte must be a date or date-time'],
    'filter creation source' => [ListCompaniesTool::class, ['filter' => ['creation_source' => ['$eq' => 'sample']]], 'creation_source $eq: sample is not one of'],
    'filter object' => [ListCompaniesTool::class, ['filter' => ['invalid']], 'filter field must be an object'],
    'filter operator object' => [ListCompaniesTool::class, ['filter' => ['name' => 'software']], 'name takes an operator object'],
    'sort object' => [ListCompaniesTool::class, ['sort' => 'name'], 'sort'],
    'sort field' => [ListCompaniesTool::class, ['sort' => ['direction' => 'asc']], 'field'],
    'sort direction' => [ListCompaniesTool::class, ['sort' => ['field' => 'name', 'direction' => 'sideways']], 'sort.direction'],
    'include list' => [ListCompaniesTool::class, ['include' => ['primary' => 'creator']], 'include'],
    'people relation ids' => [ListPeopleTool::class, ['filter' => ['company' => ['$in' => []]]], 'company $in must be a list of record IDs'],
    'people relation id' => [ListPeopleTool::class, ['filter' => ['company' => ['$in' => ['abc']]]], 'company $in: abc is not a record ID'],
    'opportunity stale days minimum' => [ListOpportunitiesTool::class, ['filter' => ['stale_days' => ['$gte' => 0]]], 'stale_days takes'],
    'opportunity stale days maximum' => [ListOpportunitiesTool::class, ['filter' => ['stale_days' => ['$gte' => 3651]]], 'stale_days takes'],
    'task assigned to me' => [ListTasksTool::class, ['filter' => ['assigned_to_me' => ['$eq' => 'maybe']]], 'assigned_to_me takes'],
    'task not assigned to me' => [ListTasksTool::class, ['filter' => ['assigned_to_me' => ['$eq' => false]]], 'assigned_to_me takes'],
    'task assignees' => [ListTasksTool::class, ['filter' => ['assignees' => ['$gte' => 'user-id']]], 'assignees takes $in, $not_in or $is_empty'],
    'note relation' => [ListNotesTool::class, ['filter' => ['companies' => ['$eq' => 'x']]], 'companies takes $in, $not_in or $is_empty'],
]);

it('computes task due status in the caller timezone', function (): void {
    $this->travelTo(Date::parse('2026-08-26 06:30:00 UTC'));
    $this->user->update(['timezone' => 'America/Los_Angeles']);
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $dueDate = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
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
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();
    $closedWon = $stage->options()->withoutGlobalScopes()->where('name', 'Closed Won')->firstOrFail();
    $unwon = CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $stage->getKey(),
        'name' => 'Unwon',
        'sort_order' => 99,
    ]);
    $wonOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $unwonOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

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

it('keeps custom-field definition reads scoped to the current workspace', function (): void {
    $other = User::factory()->withPersonalWorkspace()->create();
    $otherField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $other->currentWorkspace->getKey())
        ->firstOrFail();

    RelaticleServer::actingAs($this->user)
        ->tool(ListCustomFieldsTool::class)
        ->assertOk()
        ->assertDontSee($otherField->id);
});

it('refuses the workspace-wide activity feed to a member without activity access', function (): void {
    $company = Company::factory()->for($this->workspace)->create();

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);

    RelaticleServer::actingAs($member->fresh())
        ->tool(ListActivityTool::class, [])
        ->assertHasErrors();

    RelaticleServer::actingAs($member->fresh())
        ->tool(ListActivityTool::class, ['record_type' => 'company', 'record_id' => $company->id])
        ->assertOk();
});

it('names the channel of each change in the activity it returns', function (): void {
    $company = Company::withoutEvents(fn (): Company => Company::factory()
        ->recycle([$this->user, $this->workspace])
        ->create(['name' => 'Before']));

    $this->actingAs($this->user);
    CurrentSource::during(CreationSource::API, fn (): Company => resolve(UpdateCompany::class)->execute($this->user, $company, ['name' => 'After']));

    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, ['record_type' => 'company', 'record_id' => $company->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.source', 'api')
            ->etc());

    Activity::withoutGlobalScopes()->where('subject_id', $company->getKey())->update(['properties' => '{}']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, ['record_type' => 'company', 'record_id' => $company->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.source', null)
            ->etc());
});

it('names every known channel and null in its description', function (): void {
    $description = resolve(ListActivityTool::class)->description();

    foreach (CreationSource::values() as $value) {
        expect($description)->toContain($value);
    }

    expect($description)->toContain('null');
});
