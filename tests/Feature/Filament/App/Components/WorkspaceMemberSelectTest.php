<?php

declare(strict_types=1);

use App\Filament\Components\Forms\WorkspaceMemberSelect;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;

mutates(WorkspaceMemberSelect::class);

/**
 * Filament::setTenant() dispatches TenantSet with the authenticated user, so the
 * actor has to be bound before the tenant is.
 *
 * @return array{0: Workspace, 1: User, 2: User}
 */
function workspaceMemberSelectWorkspace(): array
{
    $owner = User::factory()->create(['name' => 'Zoe Zimmer']);
    $workspace = Workspace::factory()->create(['user_id' => $owner->getKey()]);
    $member = User::factory()->create(['name' => 'Alice Anderson']);
    $workspace->users()->attach($member, ['role' => 'editor']);

    test()->actingAs($owner);
    Filament::setTenant($workspace);

    return [$workspace, $owner, $member];
}

/**
 * The member label carries the user's avatar as markup, so ordering and the
 * "(You)" suffix are asserted against the text a user reads.
 */
function memberOptionText(string $label): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($label)));
}

it('orders the acting user first and the rest by name', function (): void {
    workspaceMemberSelectWorkspace();

    $ordered = User::query()
        ->tap(WorkspaceMemberSelect::currentWorkspaceMembers())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Zoe Zimmer', 'Alice Anderson']);
});

it('orders a different acting user first, proving the actor is resolved at query time', function (): void {
    [, , $member] = workspaceMemberSelectWorkspace();

    $this->actingAs($member);

    $ordered = User::query()
        ->tap(WorkspaceMemberSelect::currentWorkspaceMembers())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Alice Anderson', 'Zoe Zimmer']);
});

it('excludes users who are not in the current workspace', function (): void {
    [, $owner] = workspaceMemberSelectWorkspace();
    $outsider = User::factory()->withWorkspace()->create(['name' => 'Otto Outsider']);

    $this->actingAs($owner);

    $names = User::query()
        ->tap(WorkspaceMemberSelect::currentWorkspaceMembers())
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Zoe Zimmer', 'Alice Anderson'])
        ->and($names)->not->toContain($outsider->name);
});

it('includes the workspace owner, who holds no membership row', function (): void {
    [$workspace, $owner] = workspaceMemberSelectWorkspace();

    $this->actingAs($owner);

    expect($workspace->users()->whereKey($owner->getKey())->exists())->toBeFalse()
        ->and(User::query()->tap(WorkspaceMemberSelect::currentWorkspaceMembers())->pluck('id')->all())
        ->toContain($owner->getKey());
});

it('returns no members when no tenant is bound rather than falling back to every user', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant(null);

    expect(User::query()->tap(WorkspaceMemberSelect::currentWorkspaceMembers())->count())->toBe(0);
});

it('presets searchable and preload', function (): void {
    $select = WorkspaceMemberSelect::make('assignees');

    expect($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue();
});

it('orders the acting user first through the real BelongsToMany relationship path, DISTINCT included', function (): void {
    [$workspace, $owner, $member] = workspaceMemberSelectWorkspace();
    $task = Task::factory()->create(['workspace_id' => $workspace->getKey()]);
    $task->assignees()->attach([$member->getKey(), $owner->getKey()]);

    $this->actingAs($owner);

    $component = WorkspaceMemberSelect::make('assignees')
        ->model($task)
        ->relationship('assignees', 'name');

    $options = $component->getOptionsFromRelationship();

    expect(array_map(memberOptionText(...), array_values($options)))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
        'Alice Anderson',
    ]);

    $searchResults = $component->getSearchResultsFromRelationship('Zo');

    expect(array_map(memberOptionText(...), array_values($searchResults)))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
    ]);
});

it('keeps a user outside the workspace out of the relationship option set', function (): void {
    [$workspace, $owner] = workspaceMemberSelectWorkspace();
    $outsider = User::factory()->withWorkspace()->create(['name' => 'Otto Outsider']);
    $task = Task::factory()->create(['workspace_id' => $workspace->getKey()]);

    $this->actingAs($owner);

    $component = WorkspaceMemberSelect::make('assignees')
        ->model($task)
        ->relationship('assignees', 'name');

    // Filament builds the field's `in` validation rule from this same option query,
    // so an id it cannot resolve is what makes a hand-crafted payload fail. The
    // rejection itself is asserted end-to-end in TaskResourceTest.
    expect($component->getOptionsFromRelationship())->not->toHaveKey($outsider->getKey())
        ->and($component->getSearchResultsFromRelationship('Otto'))->toBe([]);
});

it('labels the acting user with the "(You)" suffix via getOptionLabelFromRecordUsing', function (): void {
    [, $owner, $member] = workspaceMemberSelectWorkspace();

    $this->actingAs($owner);

    $select = WorkspaceMemberSelect::make('member');

    expect(memberOptionText($select->getOptionLabelFromRecord($owner)))
        ->toBe(__('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']))
        ->and(memberOptionText($select->getOptionLabelFromRecord($member)))
        ->toBe('Alice Anderson')
        ->and($select->getOptionLabelFromRecord($owner))
        ->toContain($owner->getFilamentAvatarUrl());
});
