<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\People\CreatePeople;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Note\ListNotesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

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

it('serves an empty page for a page number no list can reach', function (mixed $page): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Company::factory()->for($user->currentWorkspace)->create(['name' => 'Acme']);

    $data = json_decode(new ListCompaniesTool()->handle(new Request(['page' => $page])), true);

    expect($data['data'])->toBe([])
        ->and($data['total'])->toBeGreaterThan(0);
})->with([
    'the largest integer' => [PHP_INT_MAX],
    'a float past the integer range' => [1.0e30],
    'a numeric string' => ['9223372036854775807'],
]);

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
        ->and($result['error'])->toContain('status does not support $contains.');
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

it('offers only the custom fields a list can be sorted by', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $sort = (new ListPeopleTool)->schema(new JsonSchemaTypeFactory)['sort']->toArray()['description'];

    expect($sort)->toStartWith('Sort by one of: name, created_at, updated_at, job_title.');
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

    $enterpriseId = (string) CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->value('id');

    app(SetCustomFieldOptions::class)->execute($user, $field, [
        'options' => [['id' => $enterpriseId, 'name' => 'Enterprise', 'was' => 'Enterprise'], ['name' => 'Mid-Market']],
        'replacements' => [],
        'removed' => [],
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

it('treats a member who left the workspace as no member on every member relation', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;
    $departed = User::factory()->create();

    $orphan = Company::factory()->for($workspace)->create(['name' => 'Orphan', 'creator_id' => $departed->getKey(), 'account_owner_id' => $departed->getKey()]);
    $kept = Company::factory()->for($workspace)->create(['name' => 'Kept', 'creator_id' => $user->getKey(), 'account_owner_id' => $user->getKey()]);
    Task::factory()->for($workspace)->create(['title' => 'Orphan task'])->assignees()->attach($departed);
    Task::factory()->for($workspace)->create(['title' => 'Kept task'])->assignees()->attach($user);

    $companies = fn (array $filter): array => Arr::pluck(listToolRows((new ListCompaniesTool)->handle(new Request(['filter' => $filter]))), 'attributes.name');
    $tasks = fn (array $filter): array => Arr::pluck(listToolRows((new ListTasksTool)->handle(new Request(['filter' => $filter]))), 'attributes.title');
    $departedId = (string) $departed->getKey();

    expect($companies(['creator' => ['$is_empty' => true]]))->toBe(['Orphan'])
        ->and($companies(['creator' => ['$is_empty' => false]]))->toBe(['Kept'])
        ->and($companies(['creator' => ['$in' => [$departedId]]]))->toBe([])
        ->and($companies(['accountOwner' => ['$is_empty' => true]]))->toBe(['Orphan'])
        ->and($companies(['accountOwner' => ['$not_in' => [$departedId]], 'name' => ['$contains' => 'Orphan']]))->toBe(['Orphan'])
        ->and($tasks(['assignees' => ['$is_empty' => true]]))->toBe(['Orphan task'])
        ->and($tasks(['assignees' => ['$in' => [$departedId]]]))->toBe([]);
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

it('reads a bare date on a date-time field as the day of the viewer', function (Closure $filter): void {
    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Yerevan']);
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;
    $dueDate = WorkspaceCustomField::byCode($workspace->getKey(), 'task', 'due_date');

    TenantContextService::setTenantId($workspace->getKey());

    foreach (['2026-10-09 19:59:59', '2026-10-09 20:00:00', '2026-10-10 19:59:59', '2026-10-10 20:00:00'] as $instant) {
        Task::factory()->for($workspace)->create(['title' => $instant, 'created_at' => $instant])->saveCustomFieldValue($dueDate, $instant);
    }

    TenantContextService::setTenantId(null);

    $titles = fn (string $operator): array => collect(listToolRows((new ListTasksTool)->handle(new Request(['filter' => $filter([$operator => '2026-10-10'])]))))
        ->pluck('attributes.title')->sort()->values()->all();

    expect($titles('$eq'))->toBe(['2026-10-09 20:00:00', '2026-10-10 19:59:59'])
        ->and($titles('$lt'))->toBe(['2026-10-09 19:59:59'])
        ->and($titles('$lte'))->toBe(['2026-10-09 19:59:59', '2026-10-09 20:00:00', '2026-10-10 19:59:59'])
        ->and($titles('$gte'))->toBe(['2026-10-09 20:00:00', '2026-10-10 19:59:59', '2026-10-10 20:00:00'])
        ->and($titles('$gt'))->toBe(['2026-10-10 20:00:00']);
})->with([
    'a native field' => [fn (array $operators): array => ['created_at' => $operators]],
    'a custom field' => [fn (array $operators): array => ['custom_fields' => ['due_date' => $operators]]],
]);

it('reads a padded operand as its trimmed value and rejects a blank one', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Company::factory()->for($user->currentWorkspace)->create(['name' => 'Acme']);
    Company::factory()->for($user->currentWorkspace)->create(['name' => 'Globex']);

    $padded = listToolRows((new ListCompaniesTool)->handle(new Request(['filter' => ['name' => ['$eq' => ' Acme ']]])));
    $blank = json_decode((new ListCompaniesTool)->handle(new Request(['filter' => ['name' => ['$contains' => '']]])), true);

    expect(Arr::pluck($padded, 'attributes.name'))->toBe(['Acme'])
        ->and($blank['error'])->toContain('name $contains must be a non-empty string, or use $is_empty for records without a value.');
});

it('names the node to fix in a nested filter error', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'filter' => ['$or' => [['name' => ['$eq' => 'Acme']], ['name' => ['$eq' => ['a', 'b']]]]],
    ])), true);

    expect($result['error'])->toBe('filter.$or.1.name.$eq: name $eq must be a non-empty string, or use $is_empty for records without a value.');
});

it('asks for a utc offset on a date-time filter operand that has none', function (Closure $filter, string $name): void {
    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Yerevan']);
    $this->actingAs($user);
    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Call Ana', 'created_at' => '2026-10-10 12:00:00']);

    $without = json_decode((new ListTasksTool)->handle(new Request(['filter' => $filter('2026-10-10T15:00:00')])), true);
    $with = listToolRows((new ListTasksTool)->handle(new Request(['filter' => $filter('2026-10-10T15:00:00+04:00')])));

    expect($without['error'])->toContain("{$name} \$gte needs a UTC offset, such as 2026-10-10T15:00:00+04:00")
        ->and($with)->toBeArray()->not->toHaveKey('error');
})->with([
    'a native field' => [fn (string $operand): array => ['created_at' => ['$gte' => $operand]], 'created_at'],
    'a custom field' => [fn (string $operand): array => ['custom_fields' => ['due_date' => ['$gte' => $operand]]], 'due_date'],
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

    $sample = $modelClass::factory()->for($workspace)->create(['creation_source' => CreationSource::SAMPLE]);
    $modelClass::factory()->for($workspace)->create(['creation_source' => CreationSource::WEB]);

    $rows = listToolRows(resolve($toolClass)->handle(new Request(['filter' => ['creation_source' => ['$eq' => 'sample']]])));

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

    $result = json_decode(resolve(ListCompaniesTool::class)->handle(new Request(['filter' => ['creation_source' => ['$eq' => 'system']]])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('creation_source $eq: system is not one of');
});

it('excludes options and keeps tasks without a status', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = WorkspaceCustomField::byCode($workspace->getKey(), 'task', 'status');

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

it('names the replacement for an argument the list tools no longer take', function (string $argument, string $replacement): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([$argument => 'x'])), true);

    expect($result)->toBe(['error' => "{$argument} was replaced. Use {$replacement}."]);
})->with([
    'search' => ['search', 'name or title with $contains'],
    'created_after' => ['created_after', 'created_at with $gte'],
    'assignee_ids' => ['assignee_ids', 'assignees with $in'],
]);

it('rejects an argument a list tool does not take instead of listing every record', function (string $argument, mixed $value): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentWorkspace)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([$argument => $value])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain("{$argument} is not accepted here");
})->with([
    'the custom_fields object this tool took before' => ['custom_fields', ['status' => ['$eq' => 'Done']]],
    'a filter name sent beside filter' => ['assigned_to_me', true],
    'a misspelled filter' => ['filters', ['title' => ['$eq' => 'x']]],
]);

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

    expect($result['error'])->toContain('takes an operator object, for example {"$eq": ...}.');
});

function hideLinkedinFromPeopleList(User $user): void
{
    $field = WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), 'people', 'linkedin');

    $field->settings = CustomFieldSettingsData::from(['visible_in_list' => false, 'list_toggleable_hidden' => false, 'visible_in_view' => true]);
    $field->save();
}

/**
 * @param  array<string, mixed>  $filter
 * @return list<string>
 */
function peopleListColumnKeys(User $user, array $filter): array
{
    app(CreatePeople::class)->execute($user, [
        'name' => 'Ada Lovelace',
        'custom_fields' => ['linkedin' => ['linkedin.com/in/ada'], 'job_title' => 'Engineer'],
    ]);

    $block = json_decode((new ListPeopleTool)->handle(new Request(['filter' => $filter])), true)['display_block'];

    return array_column($block['columns'], 'key');
}

it('shows a filtered custom field as a column wherever the filter places it', function (array $filter): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    hideLinkedinFromPeopleList($user);

    expect(peopleListColumnKeys($user, $filter))->toContain('linkedin');
})->with([
    'at the root' => [['custom_fields' => ['linkedin' => ['$is_empty' => false]]]],
    'under $or' => [['$or' => [['custom_fields' => ['linkedin' => ['$is_empty' => false]]], ['custom_fields' => ['job_title' => ['$is_empty' => false]]]]]],
    'under $and' => [['$and' => [['custom_fields' => ['linkedin' => ['$is_empty' => false]]], ['custom_fields' => ['job_title' => ['$is_empty' => false]]]]]],
    'under $not' => [['$not' => ['custom_fields' => ['linkedin' => ['$is_empty' => true]]]]],
    'under $and then $or' => [['$and' => [['$or' => [['custom_fields' => ['linkedin' => ['$is_empty' => false]]], ['custom_fields' => ['job_title' => ['$is_empty' => false]]]]]]]],
]);

it('leaves a column out when the filtered code belongs to a related record', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    hideLinkedinFromPeopleList($user);

    $filter = ['$or' => [['company' => ['custom_fields' => ['linkedin' => ['$is_empty' => false]]]], ['custom_fields' => ['job_title' => ['$is_empty' => false]]]]];

    expect(peopleListColumnKeys($user, $filter))->not->toContain('linkedin');
});

/**
 * @param  array<string, mixed>  $filter
 * @return list<string>
 */
function peopleIdsWithoutWorkspaceContext(array $filter): array
{
    resolve(CurrentWorkspace::class)->forget();

    $payload = json_decode((new ListPeopleTool)->handle(new Request(['filter' => $filter])), true);

    expect($payload)->not->toHaveKey('error');

    return collect($payload['data'])->pluck('id')->sort()->values()->all();
}

it('ignores a related record from another workspace when no workspace is ambient', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = Company::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Holdings']);
    Opportunity::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['company_id' => $foreign->id, 'name' => 'Foreign Deal']);
    $person = People::factory()->recycle([$user, $user->personalWorkspace()])->create();
    DB::table('people')->where('id', $person->id)->update(['company_id' => $foreign->id]);

    expect(peopleIdsWithoutWorkspaceContext(['company' => ['name' => ['$contains' => 'Foreign']]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['opportunities' => ['name' => ['$eq' => 'Foreign Deal']]]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['$in' => [$foreign->id]]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['$is_empty' => false]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['$is_empty' => true]]))->toBe([$person->id])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['$not_in' => [$foreign->id]]]))->toBe([$person->id]);
});

it('ignores a second-hop record from another workspace when no workspace is ambient', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreignDeal = Opportunity::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Deal']);
    $company = Company::factory()->recycle([$user, $user->personalWorkspace()])->create();
    $person = People::factory()->recycle([$user, $user->personalWorkspace()])->create(['company_id' => $company->id]);
    DB::table('opportunities')->where('id', $foreignDeal->id)->update(['company_id' => $company->id]);

    expect(peopleIdsWithoutWorkspaceContext(['company' => ['opportunities' => ['name' => ['$eq' => 'Foreign Deal']]]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['opportunities' => ['$in' => [$foreignDeal->id]]]]))->toBe([])
        ->and(peopleIdsWithoutWorkspaceContext(['company' => ['opportunities' => ['$is_empty' => true]]]))->toBe([$person->id]);
});

it('bounds the $not complement to the acting workspace when no workspace is ambient', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    People::factory()->recycle([$user, $user->personalWorkspace()])->create(['name' => 'Ana Reyes']);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    peopleIdsWithoutWorkspaceContext(['$not' => ['name' => ['$contains' => 'ana']]]);

    $filtered = (string) Arr::first($statements, fn (string $sql): bool => str_contains($sql, 'not exists'));

    expect(Str::after($filtered, 'not exists'))->toContain('"people"."workspace_id"');
});
