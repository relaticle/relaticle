<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(WorkspaceMembers::class);

it('prevents an admin of workspace A from removing a member of workspace B', function (): void {
    $attacker = User::factory()->withPersonalWorkspace()->create();
    $victimOwner = User::factory()->withPersonalWorkspace()->create();
    $victimWorkspace = $victimOwner->personalWorkspace();

    $victimMember = User::factory()->create();
    $victimWorkspace->users()->attach($victimMember, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(WorkspaceMembers::class, ['workspace' => $attacker->personalWorkspace()])
        ->callAction(TestAction::make('removeWorkspaceMember')->table($victimMember->id)));

    expect($victimMember->fresh()->belongsToWorkspace($victimWorkspace))->toBeTrue();
});

it('prevents an admin of workspace A from changing the role of a member of workspace B', function (): void {
    $attacker = User::factory()->withPersonalWorkspace()->create();
    $victimOwner = User::factory()->withPersonalWorkspace()->create();
    $victimWorkspace = $victimOwner->personalWorkspace();

    $victimMember = User::factory()->create();
    $victimWorkspace->users()->attach($victimMember, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(WorkspaceMembers::class, ['workspace' => $attacker->personalWorkspace()])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($victimMember->id), ['role' => WorkspaceRole::Viewer->value]));

    expect($victimMember->fresh()->workspaceRole($victimWorkspace)->key)->toBe(WorkspaceRole::Editor->value);
});

it('does not list members of another workspace', function (): void {
    $attacker = User::factory()->withPersonalWorkspace()->create();

    $victimOwner = User::factory()->withPersonalWorkspace()->create();
    $victimMember = User::factory()->create();
    $victimOwner->personalWorkspace()->users()->attach($victimMember, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($attacker);

    livewire(WorkspaceMembers::class, ['workspace' => $attacker->personalWorkspace()])
        ->assertDontSee($victimMember->email)
        ->assertDontSee($victimOwner->email);
});

it('prevents an admin of workspace A from revoking an invitation belonging to workspace B', function (): void {
    $attacker = User::factory()->withPersonalWorkspace()->create();
    $attackerWorkspace = $attacker->personalWorkspace();

    $victimOwner = User::factory()->withPersonalWorkspace()->create();
    $victimWorkspace = $victimOwner->personalWorkspace();

    $victimInvitation = $victimWorkspace->workspaceInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    $component = livewire(WorkspaceMembers::class, ['workspace' => $attackerWorkspace]);

    $component->assertDontSee('bystander@example.com');

    rescue(fn () => $component->callAction(
        TestAction::make('revokeWorkspaceInvitation')->table($victimInvitation->id)
    ));

    expect($victimWorkspace->workspaceInvitations()->whereKey($victimInvitation->id)->exists())->toBeTrue();
});

it('prevents an admin of workspace A from resending an invitation belonging to workspace B', function (): void {
    Mail::fake();

    $attacker = User::factory()->withPersonalWorkspace()->create();
    $attackerWorkspace = $attacker->personalWorkspace();

    $victimOwner = User::factory()->withPersonalWorkspace()->create();
    $victimInvitation = $victimOwner->personalWorkspace()->workspaceInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(WorkspaceMembers::class, ['workspace' => $attackerWorkspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($victimInvitation->id)));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
