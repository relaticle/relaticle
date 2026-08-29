<?php

declare(strict_types=1);

use App\Filament\Components\Forms\TeamMemberSelect;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;

mutates(TeamMemberSelect::class);

/**
 * Filament::setTenant() dispatches TenantSet with the authenticated user, so the
 * actor has to be bound before the tenant is.
 *
 * @return array{0: Team, 1: User, 2: User}
 */
function teamMemberSelectWorkspace(): array
{
    $owner = User::factory()->create(['name' => 'Zoe Zimmer']);
    $team = Team::factory()->create(['user_id' => $owner->getKey()]);
    $member = User::factory()->create(['name' => 'Alice Anderson']);
    $team->users()->attach($member, ['role' => 'editor']);

    test()->actingAs($owner);
    Filament::setTenant($team);

    return [$team, $owner, $member];
}

it('orders the acting user first and the rest by name', function (): void {
    teamMemberSelectWorkspace();

    $ordered = User::query()
        ->tap(TeamMemberSelect::currentTeamMembers())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Zoe Zimmer', 'Alice Anderson']);
});

it('orders a different acting user first, proving the actor is resolved at query time', function (): void {
    [, , $member] = teamMemberSelectWorkspace();

    $this->actingAs($member);

    $ordered = User::query()
        ->tap(TeamMemberSelect::currentTeamMembers())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Alice Anderson', 'Zoe Zimmer']);
});

it('excludes users who are not in the current workspace', function (): void {
    [, $owner] = teamMemberSelectWorkspace();
    $outsider = User::factory()->withTeam()->create(['name' => 'Otto Outsider']);

    $this->actingAs($owner);

    $names = User::query()
        ->tap(TeamMemberSelect::currentTeamMembers())
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Zoe Zimmer', 'Alice Anderson'])
        ->and($names)->not->toContain($outsider->name);
});

it('includes the workspace owner, who holds no membership row', function (): void {
    [$team, $owner] = teamMemberSelectWorkspace();

    $this->actingAs($owner);

    expect($team->users()->whereKey($owner->getKey())->exists())->toBeFalse()
        ->and(User::query()->tap(TeamMemberSelect::currentTeamMembers())->pluck('id')->all())
        ->toContain($owner->getKey());
});

it('returns no members when no tenant is bound rather than falling back to every user', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);
    Filament::setTenant(null);

    expect(User::query()->tap(TeamMemberSelect::currentTeamMembers())->count())->toBe(0);
});

it('presets searchable and preload', function (): void {
    $select = TeamMemberSelect::make('assignees');

    expect($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue();
});

it('orders the acting user first through the real BelongsToMany relationship path, DISTINCT included', function (): void {
    [$team, $owner, $member] = teamMemberSelectWorkspace();
    $task = Task::factory()->create(['team_id' => $team->getKey()]);
    $task->assignees()->attach([$member->getKey(), $owner->getKey()]);

    $this->actingAs($owner);

    $component = TeamMemberSelect::make('assignees')
        ->model($task)
        ->relationship('assignees', 'name');

    $options = $component->getOptionsFromRelationship();

    expect(array_values($options))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
        'Alice Anderson',
    ]);

    $searchResults = $component->getSearchResultsFromRelationship('Zo');

    expect(array_values($searchResults))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
    ]);
});

it('keeps a user outside the workspace out of the relationship option set', function (): void {
    [$team, $owner] = teamMemberSelectWorkspace();
    $outsider = User::factory()->withTeam()->create(['name' => 'Otto Outsider']);
    $task = Task::factory()->create(['team_id' => $team->getKey()]);

    $this->actingAs($owner);

    $component = TeamMemberSelect::make('assignees')
        ->model($task)
        ->relationship('assignees', 'name');

    // Filament builds the field's `in` validation rule from this same option query,
    // so an id it cannot resolve is what makes a hand-crafted payload fail. The
    // rejection itself is asserted end-to-end in TaskResourceTest.
    expect($component->getOptionsFromRelationship())->not->toHaveKey($outsider->getKey())
        ->and($component->getSearchResultsFromRelationship('Otto'))->toBe([]);
});

it('labels the acting user with the "(You)" suffix via getOptionLabelFromRecordUsing', function (): void {
    [, $owner, $member] = teamMemberSelectWorkspace();

    $this->actingAs($owner);

    $select = TeamMemberSelect::make('member');

    expect($select->getOptionLabelFromRecord($owner))
        ->toBe(__('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']))
        ->and($select->getOptionLabelFromRecord($member))
        ->toBe('Alice Anderson');
});
