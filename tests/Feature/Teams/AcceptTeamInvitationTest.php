<?php

declare(strict_types=1);

use App\Actions\Jetstream\AcceptTeamInvitation;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;

mutates(TeamInvitation::class, AcceptTeamInvitation::class);

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
    $joinUrl = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);

    $this->actingAs($this->user)->get($acceptUrl)->assertOk();

    expect($this->team->fresh()->hasUser($this->user))->toBeFalse();

    $this->actingAs($this->user)
        ->post($joinUrl)
        ->assertRedirect(config('fortify.home'));

    expect($this->team->fresh()->hasUser($this->user))->toBeTrue();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
    expect($this->user->fresh()->current_team_id)->toBe($this->team->id);
});

test('expired invitation shows the expired state', function () {
    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'expired');

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
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'expired');
});

test('invitation with wrong email shows the wrong-account screen, not a 403', function () {
    $invitedUser = User::factory()->withPersonalTeam()->create(['email' => 'invited@example.com']);
    $wrongUser = User::factory()->withPersonalTeam()->create(['email' => 'wrong@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->actingAs($wrongUser)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'wrong-account');

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

    $joinUrl = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);

    $this->actingAs($this->user)->post($joinUrl);

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

    $joinUrl = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);

    $this->actingAs($user)
        ->post($joinUrl)
        ->assertForbidden();

    expect($team->fresh()->hasUser($user))->toBeFalse();
});

test('a GET on the accept link never joins the team', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($this->team->name);

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('the token accept route never leaks the token via the Referer header', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('a POST joins the team', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue()
        ->and(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('a mismatched email gets the wrong-account screen not a 403', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $other = User::factory()->create(['email' => 'someone-else@example.test']);

    $this->actingAs($other)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee('invitee@example.test')
        ->assertSee('someone-else@example.test');

    expect($other->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('email matching is case insensitive', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'INVITEE@example.test']);

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue();
});

test('an expired invitation shows the expired state', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->expires_at = now()->subDay();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('teams.accept.expired.heading'));
});

test('two concurrent accepts attach exactly one membership', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)->post(route('team-invitations.token.join', ['token' => $raw]));
    $this->actingAs($invitee)->post(route('team-invitations.token.join', ['token' => $raw]));

    expect($this->team->users()->where('users.id', $invitee->id)->count())->toBe(1);
});

test('a legacy signed link still resolves and its POST still joins', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(3),
    ]);

    $invitee = User::factory()->create(['email' => 'legacy@example.test']);

    $showUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);
    $this->actingAs($invitee)->get($showUrl)->assertOk();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();

    $joinUrl = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);
    $this->actingAs($invitee)->post($joinUrl)->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue();
});

test('a GET when the user already belongs to the team redirects without erroring', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $this->team->users()->attach($invitee, ['role' => 'editor']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(config('fortify.home'));

    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('a POST for an already-member user cleans up the stale invitation without erroring', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $this->team->users()->attach($invitee, ['role' => 'editor']);

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($this->team->users()->where('users.id', $invitee->id)->count())->toBe(1)
        ->and(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});
