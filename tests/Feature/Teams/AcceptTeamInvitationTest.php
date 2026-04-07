<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;

mutates(TeamInvitation::class);

test('valid invitation can be accepted', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($user)
        ->get($acceptUrl)
        ->assertRedirect(config('fortify.home'));

    expect($team->fresh()->hasUser($user))->toBeTrue();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
    expect($user->fresh()->current_team_id)->toBe($team->id);
});

test('expired invitation shows friendly expired page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => $user->email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.invitation-expired');

    expect($team->fresh()->hasUser($user))->toBeFalse();
});

test('null expires_at is treated as expired', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->withoutExpiry()->create([
        'team_id' => $team->id,
        'email' => $user->email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.invitation-expired');
});

test('invitation with wrong email is rejected', function () {
    $invitedUser = User::factory()->withPersonalTeam()->create(['email' => 'invited@example.com']);
    $wrongUser = User::factory()->withPersonalTeam()->create(['email' => 'wrong@example.com']);
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($wrongUser)
        ->get($acceptUrl)
        ->assertForbidden();

    expect($team->fresh()->hasUser($wrongUser))->toBeFalse();
});

test('invitation with invalid signature is rejected', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
    ]);

    $this->actingAs($user)
        ->get("/team-invitations/{$invitation->id}?signature=invalid")
        ->assertForbidden();
});

test('accepting invitation deletes the invitation record', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
        'role' => 'admin',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($user)->get($acceptUrl);

    expect(TeamInvitation::count())->toBe(0);
});
