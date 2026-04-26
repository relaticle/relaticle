<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateTeam;
use App\Models\Team;
use App\Models\User;

mutates(Team::class);

test('creating a team auto-generates a 40-char invite_link_token', function (): void {
    $user = User::factory()->create();

    $team = resolve(CreateTeam::class)->create($user, [
        'name' => 'Acme',
        'slug' => 'acme',
        'onboarding_use_case' => 'other',
    ]);

    expect($team->invite_link_token)->toBeString()->toHaveLength(40);
});

test('tokens are unique across teams', function (): void {
    $user = User::factory()->create();

    $first = resolve(CreateTeam::class)->create($user, [
        'name' => 'Team A',
        'slug' => 'team-a',
        'onboarding_use_case' => 'other',
    ]);
    $second = resolve(CreateTeam::class)->create($user, [
        'name' => 'Team B',
        'slug' => 'team-b',
        'onboarding_use_case' => 'other',
    ]);

    expect($first->invite_link_token)->not->toBe($second->invite_link_token);
});

test('GET on a valid token renders the join confirmation page', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-confirm',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('teams.join', ['token' => $team->invite_link_token]))
        ->assertOk()
        ->assertSee('Join Acme')
        ->assertSee(route('teams.join.confirm', ['token' => $team->invite_link_token]), false);

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('POST on a valid token attaches the user and redirects', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-auth',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertRedirect(config('fortify.home'));

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($joiner->fresh()->current_team_id)->toBe($team->id);
});

test('invalid token returns 404 on GET', function (): void {
    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('teams.join', ['token' => 'nonexistenttokenvaluexxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});

test('invalid token returns 404 on POST', function (): void {
    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => 'nonexistenttokenvaluexxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});

test('team with null invite_link_token is unreachable via the original token', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-null',
        'onboarding_use_case' => 'other',
    ]);
    $originalToken = $team->invite_link_token;
    $team->update(['invite_link_token' => null]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('teams.join', ['token' => $originalToken]))
        ->assertNotFound();
});

test('guest hitting join link is redirected to login', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-guest',
        'onboarding_use_case' => 'other',
    ]);

    $this->get(route('teams.join', ['token' => $team->invite_link_token]))
        ->assertRedirect('/login');
});

test('user scheduled for deletion cannot view join confirmation', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-scheduled',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create([
        'email_verified_at' => now(),
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    $this->actingAs($joiner)
        ->get(route('teams.join', ['token' => $team->invite_link_token]))
        ->assertForbidden();

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('user scheduled for deletion cannot POST to join', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-scheduled-post',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create([
        'email_verified_at' => now(),
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertForbidden();

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('joining a team scheduled for deletion is blocked', function (): void {
    $owner = User::factory()->create();
    $team = resolve(CreateTeam::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-team-scheduled',
        'onboarding_use_case' => 'other',
    ]);
    $team->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertStatus(410);

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});
