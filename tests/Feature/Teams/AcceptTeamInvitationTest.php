<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;

mutates(TeamInvitation::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = Team::factory()->create();
});

test('valid invitation can be accepted', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertRedirect(config('fortify.home'));

    expect($this->team->fresh()->hasUser($this->user))->toBeTrue();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
    expect($this->user->fresh()->current_team_id)->toBe($this->team->id);
});

test('expired invitation shows friendly expired page', function () {
    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.invitation-expired');

    expect($this->team->fresh()->hasUser($this->user))->toBeFalse();
});

test('null expires_at is treated as expired', function () {
    $invitation = TeamInvitation::factory()->withoutExpiry()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.invitation-expired');
});

test('invitation with wrong email is rejected', function () {
    $invitedUser = User::factory()->withPersonalTeam()->create(['email' => 'invited@example.com']);
    $wrongUser = User::factory()->withPersonalTeam()->create(['email' => 'wrong@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($wrongUser)
        ->get($acceptUrl)
        ->assertForbidden();

    expect($this->team->fresh()->hasUser($wrongUser))->toBeFalse();
});

test('invitation with invalid signature is rejected', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $this->actingAs($this->user)
        ->get("/team-invitations/{$invitation->id}?signature=invalid")
        ->assertForbidden();
});

test('accepting invitation deletes the invitation record', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
        'role' => 'admin',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($this->user)->get($acceptUrl);

    expect(TeamInvitation::count())->toBe(0);
});

test('user with scheduled deletion cannot accept invitation', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $team = Team::factory()->create();
    $invitation = $team->teamInvitations()->create([
        'email' => $user->email,
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($user)
        ->get($acceptUrl)
        ->assertForbidden();

    expect($team->fresh()->hasUser($user))->toBeFalse();
});
