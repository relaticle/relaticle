<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Relaticle\CustomFields\Services\TenantContextService;

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
