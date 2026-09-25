<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Models\Workspace;

mutates(Workspace::class);

it('sets invite_link_token_expires_at to seven days from now on workspace creation', function (): void {
    $workspace = Workspace::factory()->create();

    expect($workspace->invite_link_token_expires_at)->not->toBeNull()
        ->and($workspace->invite_link_token_expires_at->between(
            now()->addDays(Workspace::INVITE_LINK_TTL_DAYS)->subMinute(),
            now()->addDays(Workspace::INVITE_LINK_TTL_DAYS)->addMinute(),
        ))->toBeTrue();
});

it('treats null expiry as expired (fail-closed)', function (): void {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['invite_link_token_expires_at' => null])->save();

    expect($workspace->isInviteLinkTokenExpired())->toBeTrue();
});

it('treats past expiry as expired', function (): void {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['invite_link_token_expires_at' => now()->subSecond()])->save();

    expect($workspace->isInviteLinkTokenExpired())->toBeTrue();
});

it('treats future expiry as active', function (): void {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['invite_link_token_expires_at' => now()->addDay()])->save();

    expect($workspace->isInviteLinkTokenExpired())->toBeFalse();
});

it('rotateInviteLink resets the expiry to seven days from now', function (): void {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    $workspace->rotateInviteLink();

    expect($workspace->isInviteLinkTokenExpired())->toBeFalse()
        ->and($workspace->invite_link_token_expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});

it('the join controller returns the expired view when the token is past its expiry', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee(__('workspaces.invite_link.expired.heading'))
        ->assertSee('This invite link has expired');

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

it('the join controller still works when the token is within expiry', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue();
});
