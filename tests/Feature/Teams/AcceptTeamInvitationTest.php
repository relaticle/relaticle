<?php

declare(strict_types=1);

use App\Actions\Jetstream\AcceptTeamInvitation;
use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(TeamInvitation::class, AcceptTeamInvitation::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = Team::factory()->create();
});

/**
 * Mint and persist a raw token for an existing invitation. issueToken() also
 * renews expires_at, so any test asserting an expiry state must set it after
 * calling this, not before.
 */
function rawTokenFor(TeamInvitation $invitation): string
{
    $rawToken = $invitation->issueToken();
    $invitation->save();

    return $rawToken;
}

test('valid invitation can be accepted', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $raw = rawTokenFor($invitation);
    $acceptUrl = route('team-invitations.token.accept', ['token' => $raw]);
    $joinUrl = route('team-invitations.token.join', ['token' => $raw]);

    $this->actingAs($this->user)->get($acceptUrl)->assertOk();

    expect($this->team->fresh()->hasUser($this->user))->toBeFalse();

    $this->actingAs($this->user)
        ->post($joinUrl)
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->team]));

    expect($this->team->fresh()->hasUser($this->user))->toBeTrue();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
    expect($this->user->fresh()->current_team_id)->toBe($this->team->id);
});

test('accepting an invitation lands the user in the app panel with a visible confirmation', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $redirect = $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]));

    $redirect->assertRedirect(Dashboard::getUrl(['tenant' => $this->team]));

    expect($redirect->headers->get('Location'))->not->toBe(config('fortify.home'))
        ->and($redirect->headers->get('Location'))->not->toBe(url('/'));

    $this->get($redirect->headers->get('Location'))
        ->assertOk()
        ->assertSee(__('teams.accept.joined', ['team' => $this->team->name]));
});

test('expired invitation shows the expired state', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $raw = rawTokenFor($invitation);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $acceptUrl = route('team-invitations.token.accept', ['token' => $raw]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'expired');

    expect($this->team->fresh()->hasUser($this->user))->toBeFalse();
});

test('null expires_at is treated as expired', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $raw = rawTokenFor($invitation);
    $invitation->forceFill(['expires_at' => null])->save();

    $acceptUrl = route('team-invitations.token.accept', ['token' => $raw]);

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

    $acceptUrl = route('team-invitations.token.accept', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($wrongUser)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'wrong-account');

    expect($this->team->fresh()->hasUser($wrongUser))->toBeFalse();
});

test('every accept-invitation exit link points into the app panel, not the marketing homepage', function (): void {
    $appUrl = url()->getAppUrl();

    // ready state: the "not now" link
    $readyInvitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $readyInvitation->issueToken();
    $readyInvitation->save();
    $readyInvitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($readyInvitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($appUrl, false);

    // wrong-account state: the "go to my workspace" link
    $wrongUser = User::factory()->withPersonalTeam()->create(['email' => 'wrong@example.com']);
    $mismatchedInvitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
    ]);

    $this->actingAs($wrongUser)
        ->get(route('team-invitations.token.accept', ['token' => rawTokenFor($mismatchedInvitation)]))
        ->assertOk()
        ->assertSee($appUrl, false);

    // expired state: the "go to my workspace" link
    $expiredInvitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);
    $expiredRaw = rawTokenFor($expiredInvitation);
    $expiredInvitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->actingAs($this->user)
        ->get(route('team-invitations.token.accept', ['token' => $expiredRaw]))
        ->assertOk()
        ->assertSee($appUrl, false);
});

test('an unknown token shows the expired state rather than leaking whether it exists', function () {
    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
    ]);

    $this->actingAs($this->user)
        ->get(route('team-invitations.token.accept', ['token' => Str::random(40)]))
        ->assertOk()
        ->assertViewIs('teams.accept-invitation')
        ->assertViewHas('state', 'expired');
});

test('accepting invitation deletes the invitation record', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $this->user->email,
        'role' => 'admin',
    ]);

    $joinUrl = route('team-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($this->user)->post($joinUrl);

    expect(TeamInvitation::count())->toBe(0);
});

test('user with scheduled deletion cannot accept invitation', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $team = Team::factory()->create();
    /** @var TeamInvitation $invitation */
    $invitation = $team->teamInvitations()->create([
        'email' => $user->email,
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $joinUrl = route('team-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($user)
        ->post($joinUrl)
        ->assertForbidden();

    expect($team->fresh()->hasUser($user))->toBeFalse();
});

test('a team scheduled for deletion cannot be joined', function (): void {
    $team = Team::factory()->create();
    $team->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    /** @var TeamInvitation $invitation */
    $invitation = $team->teamInvitations()->create([
        'email' => $this->user->email,
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $joinUrl = route('team-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($this->user)
        ->post($joinUrl)
        ->assertStatus(410);

    expect($team->fresh()->hasUser($this->user))->toBeFalse();
    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
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

test('unauthenticated attempts against the token accept route are rate limited', function (): void {
    $token = Str::random(40);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('team-invitations.token.accept', ['token' => $token]));
    }

    $this->get(route('team-invitations.token.accept', ['token' => $token]))
        ->assertStatus(429);
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

test('an invitation revoked in flight refuses instead of reporting a join that did not happen', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    TeamInvitation::query()->whereKey($invitation->id)->delete();

    expect(fn () => resolve(AcceptTeamInvitation::class)->execute($invitee, $invitation))
        ->toThrow(HttpException::class);

    expect($this->team->users()->where('users.id', $invitee->id)->exists())->toBeFalse()
        ->and($invitee->fresh()->current_team_id)->not->toBe($this->team->id);
});

test('a revoked invitation shows the expired state rather than a false success banner', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    TeamInvitation::query()->whereKey($invitation->id)->delete();

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('teams.accept.expired.heading'));

    expect($this->team->users()->where('users.id', $invitee->id)->exists())->toBeFalse();
});

test('an expired-in-flight invitation refuses even though the caller saw it as valid', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    TeamInvitation::query()->whereKey($invitation->id)->update(['expires_at' => now()->subDay()]);

    expect(fn () => resolve(AcceptTeamInvitation::class)->execute($invitee, $invitation))
        ->toThrow(HttpException::class);

    expect($this->team->users()->where('users.id', $invitee->id)->exists())->toBeFalse();
});

test('the legacy signed-URL routes are gone', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(3),
    ]);

    $invitee = User::factory()->create(['email' => 'legacy@example.test']);

    expect(Route::has('team-invitations.accept'))->toBeFalse()
        ->and(Route::has('team-invitations.join'))->toBeFalse();

    $this->actingAs($invitee)->get("/team-invitations/{$invitation->id}")->assertNotFound();
    $this->actingAs($invitee)->post("/team-invitations/{$invitation->id}")->assertNotFound();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('a GET when the user already belongs to the team redirects without erroring', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $this->team->users()->attach($invitee, ['role' => 'editor']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->team]));

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
