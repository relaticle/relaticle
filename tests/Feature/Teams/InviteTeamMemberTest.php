<?php

declare(strict_types=1);

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
