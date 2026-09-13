<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Jetstream\Jetstream;

mutates(User::class, WorkspaceMembers::class);

test('workspace member roles can be updated', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $workspace = $user->currentWorkspace;
    $workspace->users()->attach($otherUser = User::factory()->create(), ['role' => 'admin']);

    Filament::setTenant($workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($otherUser->id), data: [
            'role' => WorkspaceRole::Editor->value,
        ])
        ->assertHasNoActionErrors();

    expect($otherUser->fresh()->hasWorkspaceRole($workspace->fresh(), 'editor'))->toBeTrue();
});

test('editor cannot update workspace member roles', function () {
    $user = User::factory()->withWorkspace()->create();

    $workspace = $user->currentWorkspace;
    $workspace->users()->attach($otherUser = User::factory()->create(), ['role' => 'editor']);

    $this->actingAs($otherUser);
    Filament::setTenant($workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $otherUser->id);

    expect($otherUser->fresh()->hasWorkspaceRole($workspace->fresh(), 'editor'))->toBeTrue();
});

test('admin cannot promote another member to admin', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $editor = User::factory()->create();
    $workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($admin);
    Filament::setTenant($workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($editor->id), data: [
            'role' => WorkspaceRole::Admin->value,
        ])
        ->assertHasActionErrors(['role']);

    expect($editor->fresh()->hasWorkspaceRole($workspace->fresh(), WorkspaceRole::Editor->value))->toBeTrue();
});

test('admin cannot demote another admin', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $adminA = User::factory()->create();
    $workspace->users()->attach($adminA, ['role' => WorkspaceRole::Admin->value]);

    $adminB = User::factory()->create();
    $workspace->users()->attach($adminB, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($adminA);
    Filament::setTenant($workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $adminB->id);

    expect($adminB->fresh()->hasWorkspaceRole($workspace->fresh(), WorkspaceRole::Admin->value))->toBeTrue();
});

test('viewer is a registered workspace role with only read ability', function (): void {
    $role = Jetstream::findRole(WorkspaceRole::Viewer->value);

    expect($role)->not->toBeNull()
        ->and($role->key)->toBe('viewer')
        ->and($role->permissions)->toBe(['read']);
});
