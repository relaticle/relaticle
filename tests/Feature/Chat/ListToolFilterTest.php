<?php

declare(strict_types=1);

use App\Actions\CustomFields\AddCustomFieldOptions;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Services\Tools\CustomFieldsFilterDescriber;
use Relaticle\Chat\Tools\BaseReadListTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Note\ListNotesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(BaseReadListTool::class);

/**
 * The list tools return either a bare array of rows or a resource envelope.
 *
 * @return array<int|string, mixed>
 */
function listToolRows(string $json): array
{
    $decoded = json_decode($json, true);

    return $decoded['data'] ?? $decoded;
}

function taskCustomFieldOptionId(string $workspaceId, string $code, string $label): string
{
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')
        ->where('code', $code)
        ->firstOrFail();

    return (string) $field->options->firstWhere('name', $label)->id;
}

it('applies a filter when searching for the literal term "0" instead of returning all', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->for($workspace)->create(['name' => '0']);
    Company::factory()->for($workspace)->create(['name' => 'Acme']);
    Company::factory()->for($workspace)->create(['name' => 'Globex']);

    $tool = new ListCompaniesTool;
    $json = $tool->handle(new Request(['filter' => ['name' => ['$contains' => '0']]]));
    $data = json_decode($json, true);

    $rows = $data['data'] ?? $data;
    expect($rows)->toHaveCount(1);
});

it('restricts tasks to the current user when assigned_to_me is true', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    $colleague = User::factory()->create();
    $colleague->workspaces()->attach($workspace, ['role' => 'member']);

    $mine = Task::factory()->for($workspace)->create(['title' => 'Mine']);
    $mine->assignees()->attach($user);

    $theirs = Task::factory()->for($workspace)->create(['title' => 'Theirs']);
    $theirs->assignees()->attach($colleague);

    $rows = listToolRows((new ListTasksTool)->handle(new Request(['filter' => ['assigned_to_me' => ['$eq' => true]]])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Mine');
});

it('returns every workspace task when no filter is set', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Task::factory()->for($workspace)->create(['title' => 'Mine'])->assignees()->attach($user);
    Task::factory()->for($workspace)->create(['title' => 'Unassigned']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([])));

    expect($rows)->toHaveCount(2);
});

it('filters tasks by a choice custom field using the option label', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $open = Task::factory()->for($workspace)->create(['title' => 'Open one']);
    $done = Task::factory()->for($workspace)->create(['title' => 'Finished']);

    $open->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'To do'));
    $done->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'Done'));

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$eq' => 'Done']]],
    ])));

    TenantContextService::setTenantId(null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Finished');
});

it('filters tasks by a choice custom field using the option id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $open = Task::factory()->for($workspace)->create(['title' => 'Open one']);
    $done = Task::factory()->for($workspace)->create(['title' => 'Finished']);
    $doneId = taskCustomFieldOptionId($workspace->getKey(), 'status', 'Done');

    $open->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'To do'));
    $done->saveCustomFieldValue($statusField, $doneId);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$eq' => $doneId]]],
    ])));

    TenantContextService::setTenantId(null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Finished');
});

it('rejects a label shared by two options and asks for the id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    CustomFieldOption::query()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_id' => $statusField->getKey(),
        'name' => 'DONE',
        'sort_order' => 99,
    ]);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$eq' => 'Done']]],
    ])), true);

    TenantContextService::setTenantId(null);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('ambiguous');
});

it('rejects an unknown custom field code instead of silently returning everything', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['not_a_field' => ['$eq' => 'x']]],
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not a filterable custom field');
});

it('rejects an unknown option label instead of silently returning everything', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    TenantContextService::setTenantId($user->currentWorkspace->getKey());
    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$eq' => 'Nope']]],
    ])), true);

    TenantContextService::setTenantId(null);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('is not one of:');
});

it('rejects an operator the field does not support', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$contains' => 'Done']]],
    ])), true);

    TenantContextService::setTenantId(null);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not supported');
});

it('sorts companies by the requested column and direction', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->for($workspace)->create(['name' => 'Alpha']);
    Company::factory()->for($workspace)->create(['name' => 'Zulu']);

    $descending = listToolRows((new ListCompaniesTool)->handle(new Request(['sort' => '-name'])));
    $ascending = listToolRows((new ListCompaniesTool)->handle(new Request(['sort' => 'name'])));

    expect($descending[0]['attributes']['name'])->toBe('Zulu')
        ->and($ascending[0]['attributes']['name'])->toBe('Alpha');
});

it('reports an unknown sort column instead of failing silently', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Company::factory()->for($user->currentWorkspace)->create(['name' => 'Alpha']);

    $result = json_decode((new ListCompaniesTool)->handle(new Request(['sort' => 'not_a_column'])), true);

    expect($result)->toHaveKey('error');
});

it('can filter by a custom field immediately after creating it', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    // Warm the filter schema the way any earlier turn in the conversation would.
    (new ListCompaniesTool)->handle(new Request);

    app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise', 'SMB'],
    ]);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'filter' => ['custom_fields' => ['segment' => ['$eq' => 'Enterprise']]],
    ])), true);

    expect($result)->not->toHaveKey('error');
});

it('stops offering a custom field for filtering once it is deactivated', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $field = app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise'],
    ]);

    (new ListCompaniesTool)->handle(new Request(['filter' => ['custom_fields' => ['segment' => ['$eq' => 'Enterprise']]]]));

    app(UpdateCustomField::class)->execute($user, $field, ['active' => false]);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'filter' => ['custom_fields' => ['segment' => ['$eq' => 'Enterprise']]],
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not a filterable custom field');
});

it('can filter by an option added to an existing custom field', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $field = app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise'],
    ]);

    (new ListCompaniesTool)->handle(new Request(['filter' => ['custom_fields' => ['segment' => ['$eq' => 'Enterprise']]]]));

    app(AddCustomFieldOptions::class)->execute($user, [
        '_record_id' => $field->getKey(),
        'options' => ['Mid-Market'],
    ]);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'filter' => ['custom_fields' => ['segment' => ['$eq' => 'Mid-Market']]],
    ])), true);

    expect($result)->not->toHaveKey('error');
});

it('restricts tasks to a named colleague when assignees is set', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    $colleague = User::factory()->create();
    $workspace->users()->attach($colleague, ['role' => 'member']);

    $theirs = Task::factory()->for($workspace)->create(['title' => 'Colleague task']);
    $theirs->assignees()->attach($colleague);

    $mine = Task::factory()->for($workspace)->create(['title' => 'My task']);
    $mine->assignees()->attach($user);

    Task::factory()->for($workspace)->create(['title' => 'Unassigned task']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['assignees' => ['$in' => [(string) $colleague->getKey()]]],
    ])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Colleague task');
});

it('matches tasks assigned to any of several people', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    $one = User::factory()->create();
    $two = User::factory()->create();
    $workspace->users()->attach($one, ['role' => 'member']);
    $workspace->users()->attach($two, ['role' => 'member']);

    Task::factory()->for($workspace)->create(['title' => 'First'])->assignees()->attach($one);
    Task::factory()->for($workspace)->create(['title' => 'Second'])->assignees()->attach($two);
    Task::factory()->for($workspace)->create(['title' => 'Third']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['assignees' => ['$in' => [(string) $one->getKey(), (string) $two->getKey()]]],
    ])));

    expect($rows)->toHaveCount(2);
});

it('never leaks another workspace\'s tasks through assignees', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $outsider = User::factory()->withPersonalWorkspace()->create();
    $theirTask = Task::factory()->for($outsider->currentWorkspace)->create(['title' => 'Other workspace task']);
    $theirTask->assignees()->attach($outsider);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Mine']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['assignees' => ['$in' => [(string) $outsider->getKey()]]],
    ])));

    expect($rows)->toBeEmpty();
});

it('rejects an empty assignees list instead of returning nothing', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Alpha']);

    $result = json_decode((new ListTasksTool)->handle(new Request(['filter' => ['assignees' => ['$in' => []]]])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('assignees $in must be a list of record IDs');
});

it('filters every list tool by creation date, including tasks and notes', function (string $toolClass, string $factory): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $factory::factory()->for($user->currentWorkspace)->create();

    $tool = new $toolClass;

    expect(listToolRows($tool->handle(new Request(['filter' => ['created_at' => ['$gte' => now()->addDay()->toDateString()]]]))))->toBeEmpty()
        ->and(listToolRows($tool->handle(new Request(['filter' => ['created_at' => ['$lte' => now()->subYears(5)->toDateString()]]]))))->toBeEmpty()
        ->and(listToolRows($tool->handle(new Request(['filter' => ['created_at' => ['$gte' => now()->subYears(5)->toDateString()]]]))))->toHaveCount(1);
})->with([
    'companies' => [ListCompaniesTool::class, Company::class],
    'tasks' => [ListTasksTool::class, Task::class],
    'notes' => [ListNotesTool::class, Note::class],
]);

it('reports total and showing when results exceed one page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->count(17)->for($workspace)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['total'])->toBe(17)
        ->and($payload['showing'])->toBe(10)
        ->and($payload['data'])->toHaveCount(10);
});

it('reports total equal to showing when results fit on one page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->count(3)->for($workspace)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['total'])->toBe(3)
        ->and($payload['showing'])->toBe(3)
        ->and($payload['data'])->toHaveCount(3);
});

it('defaults to 10 rows per page when per_page is not given', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->count(30)->for($workspace)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['data'])->toHaveCount(10);
});

it('clamps per_page at 25 even when a larger value is requested', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    Company::factory()->count(30)->for($workspace)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request(['per_page' => 50])), true);

    expect($payload['data'])->toHaveCount(25);
});

it('lists only the records of the requested creation source', function (string $modelClass, string $toolClass): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    $sample = $modelClass::factory()->for($workspace)->create(['creation_source' => CreationSource::SYSTEM]);
    $modelClass::factory()->for($workspace)->create(['creation_source' => CreationSource::WEB]);

    $rows = listToolRows(resolve($toolClass)->handle(new Request(['filter' => ['creation_source' => ['$eq' => 'system']]])));

    expect(array_column($rows, 'id'))->toBe([$sample->getKey()]);
})->with([
    'companies' => [Company::class, ListCompaniesTool::class],
    'people' => [People::class, ListPeopleTool::class],
    'opportunities' => [Opportunity::class, ListOpportunitiesTool::class],
    'tasks' => [Task::class, ListTasksTool::class],
    'notes' => [Note::class, ListNotesTool::class],
]);

it('rejects an unknown creation source instead of returning an empty list', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Company::factory()->for($user->currentWorkspace)->create();

    $result = json_decode(resolve(ListCompaniesTool::class)->handle(new Request(['filter' => ['creation_source' => ['$eq' => 'sample']]])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('creation_source $eq: sample is not one of');
});

it('excludes options and keeps tasks without a status', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $open = Task::factory()->for($workspace)->create(['title' => 'Open one']);
    $done = Task::factory()->for($workspace)->create(['title' => 'Finished']);
    Task::factory()->for($workspace)->create(['title' => 'No status']);
    $open->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'To do'));
    $done->saveCustomFieldValue($statusField, taskCustomFieldOptionId($workspace->getKey(), 'status', 'Done'));

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$not_in' => ['Done']]]],
    ])));

    TenantContextService::setTenantId(null);

    expect(collect($rows)->pluck('attributes.title')->sort()->values()->all())->toBe(['No status', 'Open one']);
});

it('rejects custom_fields sent as a string instead of returning every row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => '{"status": {"$eq": "Done"}}'],
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('object keyed by field code');
});

it('treats an empty filter string as no filter', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request(['filter' => ''])));

    expect(collect($rows)->pluck('attributes.title')->all())->toBe(['Anything']);
});

it('rejects a JSON string filter instead of returning every row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Company::factory()->for($user->currentWorkspace)->create(['name' => 'Acme']);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'filter' => '{"name":{"$eq":"x"}}',
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toBe('The filter must be an object.')
        ->and($result)->not->toHaveKey('data');
});

it('shows the operator example when a bare value is given', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => 'Done']],
    ])), true);

    expect($result['error'])->toContain('must be an operator object, e.g. {"$eq": "..."}');
});

it('lists option labels for a select but not for a tags-input field with suggestions', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise', 'SMB'],
    ]);
    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    $labels = app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Labels',
        'code' => 'labels',
        'type' => 'tags-input',
    ]);
    $labels->options()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'name' => 'Priority',
        'sort_order' => 0,
    ]);

    $description = resolve(CustomFieldsFilterDescriber::class)->describe($user, 'company');
    $lines = collect(explode("\n", $description));

    TenantContextService::setTenantId(null);

    expect($lines->first(fn (string $line): bool => str_starts_with($line, '- segment')))->toContain('one of: "Enterprise", "SMB"')
        ->and($lines->first(fn (string $line): bool => str_starts_with($line, '- labels')))->not->toContain('one of:');
});

it('names the domain sub-field operators and the matching rule once per email and phone type', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $lines = collect(explode("\n", resolve(CustomFieldsFilterDescriber::class)->describe($user, 'people')));
    $email = $lines->first(fn (string $line): bool => str_starts_with($line, '- email:'));
    $phone = $lines->first(fn (string $line): bool => str_starts_with($line, '- phone:'));
    $field = $lines->first(fn (string $line): bool => str_starts_with($line, '- emails ('));
    $filterDescription = (new ListPeopleTool)->schema(new JsonSchemaTypeFactory)['filter']->toArray()['description'];

    expect($email)->toContain('operators $has_any, $has_none, $is_empty;', 'sub-field domain takes $in, $not_in', CustomFieldType::EMAIL->filterMatching())
        ->and($phone)->toContain(CustomFieldType::PHONE->filterMatching())
        ->and($phone)->not->toContain('sub-field')
        ->and($field)->toBe('- emails (Emails, email)')
        ->and($filterDescription)->toStartWith(EntityFilters::names(CrmEntity::People))
        ->and(CustomFieldFilterSchema::valueRules())->toContain('domain sub-field with $in or $not_in', CustomFieldType::PHONE->filterMatching());
});

it('renders the related entity and the field type on chat and states emptiness on an entity without custom fields', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $opportunities = resolve(CustomFieldsFilterDescriber::class)->describe($user, 'opportunity');

    expect($opportunities)->toContain('- contact (relation to people;', '- amount (Amount, currency)', '- close_date (Close Date, date)')
        ->and(resolve(CustomFieldsFilterDescriber::class)->describe($user, 'note'))->toContain('No filterable custom fields are defined');
});
