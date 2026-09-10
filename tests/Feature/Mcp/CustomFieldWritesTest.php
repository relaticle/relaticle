<?php

declare(strict_types=1);

use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\User;
use App\Rules\OwnedLookupRecords;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\RecordNameResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(BaseCreateTool::class, BaseUpdateTool::class, CustomFieldInput::class, CustomFieldOptionMap::class, OwnedLookupRecords::class, ValidCustomFields::class, RecordNameResolver::class, FormatsCustomFields::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->status = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    TenantContextService::setTenantId($this->team->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function statusOptionId(CustomField $field, string $label): string
{
    return (string) $field->options->firstWhere('name', $label)->getKey();
}

function teamScopedCompanyLookups(): Collection
{
    return collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_contains($query['query'], 'from "companies"') && str_contains($query['query'], 'team_id'),
    );
}

it('creates a task with a select value given as a label', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Label write', 'custom_fields' => ['status' => 'done']])
        ->assertOk();

    $task = Task::query()->where('title', 'Label write')->with('customFieldValues.customField.options')->firstOrFail();

    expect($task->getCustomFieldValue($this->status))->toBe(statusOptionId($this->status, 'Done'));
});

it('creates a task with a select value given as an option id', function (): void {
    $id = statusOptionId($this->status, 'In progress');

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Id write', 'custom_fields' => ['status' => $id]])
        ->assertOk();

    expect(Task::query()->where('title', 'Id write')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($this->status))->toBe($id);
});

it('rejects an unknown label and lists the valid ones', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Bad', 'custom_fields' => ['status' => 'Blocked']])
        ->assertHasErrors()
        ->assertSee('Status: option')
        ->assertSee('To do, In progress, Done');
});

it('rejects a label shared by two options and asks for the id', function (): void {
    CustomFieldOption::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $this->status->getKey(),
        'name' => 'DONE',
        'sort_order' => 99,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Ambiguous', 'custom_fields' => ['status' => 'done']])
        ->assertHasErrors()
        ->assertSee('Status: option')
        ->assertSee('ambiguous');
});

it('rejects a nested value in a multi-select field', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Nested', 'custom_fields' => ['markets' => [['EU']]]])
        ->assertHasErrors()
        ->assertSee('Markets')
        ->assertSee('array of option labels');
});

it('updates a multi-select field from mixed labels and ids', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $eu = CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'EU', 'sort_order' => 1]);
    $us = CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'US', 'sort_order' => 2]);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['markets' => ['eu', (string) $us->getKey()]]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field))->toBe([(string) $eu->getKey(), (string) $us->getKey()]);
});

it('clears a select field with null', function (): void {
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($this->status, statusOptionId($this->status, 'Done'));

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['status' => null]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($this->status))->toBeNull();
});

it('stores markdown for a rich editor field as html', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Md', 'custom_fields' => ['description' => "## Plan\n\n- call **Ada**"]])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();
    $stored = Task::query()->where('title', 'Md')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description);

    expect($stored)->toContain('<h2>Plan</h2>')->toContain('<strong>Ada</strong>');
});

it('passes html through untouched for a rich editor field', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Html', 'custom_fields' => ['description' => '<p>Already <em>html</em></p>']])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    expect(Task::query()->where('title', 'Html')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description))->toBe('<p>Already <em>html</em></p>');
});

it('escapes inline html inside markdown', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Esc', 'custom_fields' => ['description' => 'Hi <script>alert(1)</script>']])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    expect(Task::query()->where('title', 'Esc')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description))->not->toContain('<script>');
});

it('rejects a record id from another workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->personalTeam();
    $foreign = Company::factory()->create(['team_id' => $otherTeam->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Foreign', 'custom_fields' => ['related_company' => [$foreign->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong to this workspace');
});

it('accepts a record id from the caller workspace', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $own = Company::factory()->create(['team_id' => $this->team->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Own', 'custom_fields' => ['related_company' => [$own->getKey()]]])
        ->assertOk();

    expect(Task::query()->where('title', 'Own')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($field))->toBe([$own->getKey()]);
});

it('rejects a soft-deleted record id from the caller workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $trashed = Company::factory()->create(['team_id' => $this->team->getKey()]);
    $trashed->delete();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Trashed', 'custom_fields' => ['related_company' => [$trashed->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong');
});

it('rejects a nested value in a record field', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Nested record', 'custom_fields' => ['related_company' => [['id' => 'x']]]])
        ->assertHasErrors()
        ->assertSee('array of record IDs');
});

it('returns record values as id and name pairs', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Globex']);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk()
        ->assertSee('Globex');
});

it('lists tasks with record names in one team-scoped query per lookup type', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $companies = Company::factory()->count(5)->create(['team_id' => $this->team->getKey()]);
    foreach ($companies as $company) {
        Task::factory()->create(['team_id' => $this->team->getKey()])->saveCustomFieldValue($field, [$company->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});

it('reads a foreign-team record value as a null name', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->personalTeam();
    $foreign = Company::factory()->create(['team_id' => $otherTeam->getKey(), 'name' => 'Initech']);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [$foreign->getKey()]);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    $response->assertDontSee('Initech');
    $response->assertSee('"name":null');
});

it('resolves a record field with several own-team ids in one team-scoped query', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $companies = Company::factory()->count(3)->create(['team_id' => $this->team->getKey()]);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, $companies->pluck('id')->all());

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});

it('resolves a dangling record reference without one query per row', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $trashed = Company::factory()->create(['team_id' => $this->team->getKey()]);
    $trashed->delete();
    foreach (range(1, 3) as $ignored) {
        Task::factory()->create(['team_id' => $this->team->getKey()])->saveCustomFieldValue($field, [$trashed->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});
