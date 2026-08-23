<?php

declare(strict_types=1);

use App\Actions\CustomFields\AddCustomFieldOptions;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Note\ListNotesTool;
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

function taskCustomFieldOptionId(string $teamId, string $code, string $label): string
{
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', $code)
        ->firstOrFail();

    return (string) $field->options->firstWhere('name', $label)->id;
}

it('applies a filter when searching for the literal term "0" instead of returning all', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Company::factory()->for($team)->create(['name' => '0']);
    Company::factory()->for($team)->create(['name' => 'Acme']);
    Company::factory()->for($team)->create(['name' => 'Globex']);

    $tool = new ListCompaniesTool;
    $json = $tool->handle(new Request(['search' => '0']));
    $data = json_decode($json, true);

    $rows = $data['data'] ?? $data;
    expect($rows)->toHaveCount(1);
});

it('restricts tasks to the current user when assigned_to_me is set', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $colleague = User::factory()->create();
    $colleague->teams()->attach($team, ['role' => 'editor']);

    $mine = Task::factory()->for($team)->create(['title' => 'Mine']);
    $mine->assignees()->attach($user);

    $theirs = Task::factory()->for($team)->create(['title' => 'Theirs']);
    $theirs->assignees()->attach($colleague);

    $rows = listToolRows((new ListTasksTool)->handle(new Request(['assigned_to_me' => true])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Mine');
});

it('returns every workspace task when assigned_to_me is not set', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Task::factory()->for($team)->create(['title' => 'Mine'])->assignees()->attach($user);
    Task::factory()->for($team)->create(['title' => 'Unassigned']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([])));

    expect($rows)->toHaveCount(2);
});

it('filters tasks by a choice custom field using the option label', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    TenantContextService::setTenantId($team->getKey());

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $open = Task::factory()->for($team)->create(['title' => 'Open one']);
    $done = Task::factory()->for($team)->create(['title' => 'Finished']);

    $open->saveCustomFieldValue($statusField, taskCustomFieldOptionId($team->getKey(), 'status', 'To do'));
    $done->saveCustomFieldValue($statusField, taskCustomFieldOptionId($team->getKey(), 'status', 'Done'));

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'custom_fields' => ['status' => ['eq' => 'Done']],
    ])));

    TenantContextService::setTenantId(null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Finished');
});

it('rejects an unknown custom field code instead of silently returning everything', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentTeam)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'custom_fields' => ['not_a_field' => ['eq' => 'x']],
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not a filterable custom field');
});

it('rejects an unknown option label instead of silently returning everything', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    TenantContextService::setTenantId($user->currentTeam->getKey());
    Task::factory()->for($user->currentTeam)->create(['title' => 'Anything']);

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'custom_fields' => ['status' => ['eq' => 'Nope']],
    ])), true);

    TenantContextService::setTenantId(null);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not one of the options');
});

it('rejects an operator the field does not support', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    TenantContextService::setTenantId($user->currentTeam->getKey());

    $result = json_decode((new ListTasksTool)->handle(new Request([
        'custom_fields' => ['status' => ['contains' => 'Done']],
    ])), true);

    TenantContextService::setTenantId(null);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not supported');
});

it('sorts companies by the requested column and direction', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Company::factory()->for($team)->create(['name' => 'Alpha']);
    Company::factory()->for($team)->create(['name' => 'Zulu']);

    $descending = listToolRows((new ListCompaniesTool)->handle(new Request(['sort' => '-name'])));
    $ascending = listToolRows((new ListCompaniesTool)->handle(new Request(['sort' => 'name'])));

    expect($descending[0]['attributes']['name'])->toBe('Zulu')
        ->and($ascending[0]['attributes']['name'])->toBe('Alpha');
});

it('reports an unknown sort column instead of failing silently', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Company::factory()->for($user->currentTeam)->create(['name' => 'Alpha']);

    $result = json_decode((new ListCompaniesTool)->handle(new Request(['sort' => 'not_a_column'])), true);

    expect($result)->toHaveKey('error');
});

it('can filter by a custom field immediately after creating it', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

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
        'custom_fields' => ['segment' => ['eq' => 'Enterprise']],
    ])), true);

    expect($result)->not->toHaveKey('error');
});

it('stops offering a custom field for filtering once it is deactivated', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $field = app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise'],
    ]);

    (new ListCompaniesTool)->handle(new Request(['custom_fields' => ['segment' => ['eq' => 'Enterprise']]]));

    app(UpdateCustomField::class)->execute($user, $field, ['active' => false]);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'custom_fields' => ['segment' => ['eq' => 'Enterprise']],
    ])), true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not a filterable custom field');
});

it('can filter by an option added to an existing custom field', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $field = app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => 'select',
        'options' => ['Enterprise'],
    ]);

    (new ListCompaniesTool)->handle(new Request(['custom_fields' => ['segment' => ['eq' => 'Enterprise']]]));

    app(AddCustomFieldOptions::class)->execute($user, [
        '_record_id' => $field->getKey(),
        'options' => ['Mid-Market'],
    ]);

    $result = json_decode((new ListCompaniesTool)->handle(new Request([
        'custom_fields' => ['segment' => ['eq' => 'Mid-Market']],
    ])), true);

    expect($result)->not->toHaveKey('error');
});

it('restricts tasks to a named colleague when assignee_ids is set', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $colleague = User::factory()->create();
    $team->users()->attach($colleague, ['role' => 'editor']);

    $theirs = Task::factory()->for($team)->create(['title' => 'Colleague task']);
    $theirs->assignees()->attach($colleague);

    $mine = Task::factory()->for($team)->create(['title' => 'My task']);
    $mine->assignees()->attach($user);

    Task::factory()->for($team)->create(['title' => 'Unassigned task']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'assignee_ids' => [(string) $colleague->getKey()],
    ])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['attributes']['title'])->toBe('Colleague task');
});

it('matches tasks assigned to any of several people', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $one = User::factory()->create();
    $two = User::factory()->create();
    $team->users()->attach($one, ['role' => 'editor']);
    $team->users()->attach($two, ['role' => 'editor']);

    Task::factory()->for($team)->create(['title' => 'First'])->assignees()->attach($one);
    Task::factory()->for($team)->create(['title' => 'Second'])->assignees()->attach($two);
    Task::factory()->for($team)->create(['title' => 'Third']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'assignee_ids' => [(string) $one->getKey(), (string) $two->getKey()],
    ])));

    expect($rows)->toHaveCount(2);
});

it('never leaks another workspace\'s tasks through assignee_ids', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $outsider = User::factory()->withPersonalTeam()->create();
    $theirTask = Task::factory()->for($outsider->currentTeam)->create(['title' => 'Other workspace task']);
    $theirTask->assignees()->attach($outsider);

    Task::factory()->for($user->currentTeam)->create(['title' => 'Mine']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request([
        'assignee_ids' => [(string) $outsider->getKey()],
    ])));

    expect($rows)->toBeEmpty();
});

it('ignores an empty assignee_ids list rather than returning nothing', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Task::factory()->for($user->currentTeam)->create(['title' => 'Alpha']);

    $rows = listToolRows((new ListTasksTool)->handle(new Request(['assignee_ids' => []])));

    expect($rows)->toHaveCount(1);
});

it('filters every list tool by creation date, including tasks and notes', function (string $toolClass, string $factory): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $factory::factory()->for($user->currentTeam)->create();

    $tool = new $toolClass;

    expect(listToolRows($tool->handle(new Request(['created_after' => now()->addDay()->toDateString()]))))->toBeEmpty()
        ->and(listToolRows($tool->handle(new Request(['created_before' => now()->subYears(5)->toDateString()]))))->toBeEmpty()
        ->and(listToolRows($tool->handle(new Request(['created_after' => now()->subYears(5)->toDateString()]))))->toHaveCount(1);
})->with([
    'companies' => [ListCompaniesTool::class, Company::class],
    'tasks' => [ListTasksTool::class, Task::class],
    'notes' => [ListNotesTool::class, Note::class],
]);

it('reports total and showing when results exceed one page', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Company::factory()->count(17)->for($team)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['total'])->toBe(17)
        ->and($payload['showing'])->toBe(15)
        ->and($payload['data'])->toHaveCount(15);
});

it('reports total equal to showing when results fit on one page', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Company::factory()->count(3)->for($team)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['total'])->toBe(3)
        ->and($payload['showing'])->toBe(3)
        ->and($payload['data'])->toHaveCount(3);
});
