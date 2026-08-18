<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\AddTeamMember;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

mutates(User::class);

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

test('team members can be invited to team', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(1);

    $invitation = $this->team->fresh()->teamInvitations->first();
    expect($invitation->email)->toBe('test@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->expires_at)->not->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

test('invitation expires_at is set based on config', function () {
    config(['jetstream.invitation_expiry_days' => 14]);

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'editor',
        ])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->first();
    expect((int) round($invitation->expires_at->diffInDays(now(), absolute: true)))->toBe(14);
});

test('team member invitations can be revoked', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(1);

    $invitation = $this->team->fresh()->teamInvitations->first();

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('team members cannot be invited with a disposable email address', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'burner@mailinator.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team)
        ->assertNotified(__('validation.indisposable'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('admin cannot invite a new member as admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'newadmin@example.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team)
        ->assertForbidden();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('owner can manage members and promote to admin', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    expect($owner->can('manageMembers', $team))->toBeTrue()
        ->and($owner->can('addTeamMember', $team))->toBeTrue()
        ->and($owner->can('updateTeamMember', $team))->toBeTrue()
        ->and($owner->can('removeTeamMember', $team))->toBeTrue()
        ->and($owner->can('promoteToAdmin', $team))->toBeTrue()
        ->and($owner->can('update', $team))->toBeTrue();
});

test('admin can manage members but cannot promote to admin', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $admin = User::factory()->create();
    $team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    expect($admin->can('manageMembers', $team))->toBeTrue()
        ->and($admin->can('addTeamMember', $team))->toBeTrue()
        ->and($admin->can('updateTeamMember', $team))->toBeTrue()
        ->and($admin->can('removeTeamMember', $team))->toBeTrue()
        ->and($admin->can('promoteToAdmin', $team))->toBeFalse()
        ->and($admin->can('update', $team))->toBeFalse()
        ->and($admin->can('delete', $team))->toBeFalse();
});

test('editor cannot manage members', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $editor = User::factory()->create();
    $team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    expect($editor->can('manageMembers', $team))->toBeFalse()
        ->and($editor->can('addTeamMember', $team))->toBeFalse()
        ->and($editor->can('updateTeamMember', $team))->toBeFalse()
        ->and($editor->can('removeTeamMember', $team))->toBeFalse()
        ->and($editor->can('promoteToAdmin', $team))->toBeFalse()
        ->and($editor->can('update', $team))->toBeFalse();
});

test('viewer cannot manage members', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $viewer = User::factory()->create();
    $team->users()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    expect($viewer->can('manageMembers', $team))->toBeFalse()
        ->and($viewer->can('addTeamMember', $team))->toBeFalse()
        ->and($viewer->can('updateTeamMember', $team))->toBeFalse()
        ->and($viewer->can('removeTeamMember', $team))->toBeFalse()
        ->and($viewer->can('promoteToAdmin', $team))->toBeFalse()
        ->and($viewer->can('update', $team))->toBeFalse();
});

test('inviting records the inviter and mints a token', function (): void {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'new@example.test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->first();

    expect($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->token)->not->toBeNull();
});

test('inviting lowercases a mixed-case email', function (): void {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'Mixed-Case@Example.Test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->first();

    expect($invitation->email)->toBe('mixed-case@example.test');
});

test('inviting a case-variant of an already-invited email is rejected as a duplicate', function (): void {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'bob@example.test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team);

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'Bob@Example.Test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team)
        ->assertNotified(__('This user has already been invited to the team.'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(1);
});
