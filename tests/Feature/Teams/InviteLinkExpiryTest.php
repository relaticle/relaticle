<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;

mutates(Team::class);

it('sets invite_link_token_expires_at to seven days from now on team creation', function (): void {
    $team = Team::factory()->create();

    expect($team->invite_link_token_expires_at)->not->toBeNull()
        ->and($team->invite_link_token_expires_at->between(
            now()->addDays(Team::INVITE_LINK_TTL_DAYS)->subMinute(),
            now()->addDays(Team::INVITE_LINK_TTL_DAYS)->addMinute(),
        ))->toBeTrue();
});

it('treats null expiry as expired (fail-closed)', function (): void {
    $team = Team::factory()->create();
    $team->forceFill(['invite_link_token_expires_at' => null])->save();

    expect($team->isInviteLinkTokenExpired())->toBeTrue();
});

it('treats past expiry as expired', function (): void {
    $team = Team::factory()->create();
    $team->forceFill(['invite_link_token_expires_at' => now()->subSecond()])->save();

    expect($team->isInviteLinkTokenExpired())->toBeTrue();
});

it('treats future expiry as active', function (): void {
    $team = Team::factory()->create();
    $team->forceFill(['invite_link_token_expires_at' => now()->addDay()])->save();

    expect($team->isInviteLinkTokenExpired())->toBeFalse();
});

it('rotateInviteLink resets the expiry to seven days from now', function (): void {
    $team = Team::factory()->create();
    $team->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    $team->rotateInviteLink();

    expect($team->isInviteLinkTokenExpired())->toBeFalse()
        ->and($team->invite_link_token_expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});

it('the join controller returns the expired view when the token is past its expiry', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('teams.join', ['token' => $team->invite_link_token]))
        ->assertOk()
        ->assertSee('Invite Link Expired')
        ->assertSee('This invite link has expired');

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

it('the join controller still works when the token is within expiry', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertRedirect(config('fortify.home'));

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue();
});
