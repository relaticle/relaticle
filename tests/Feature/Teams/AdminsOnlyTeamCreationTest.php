<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;

mutates(TeamPolicy::class);

it('allows any user to create a team by default (creation_admins_only off)', function (): void {
    config()->set('relaticle.teams.creation_admins_only', false);

    $user = User::factory()->create();

    expect($user->can('create', Team::class))->toBeTrue();
});

it('blocks a user with no team from creating a team when creation_admins_only is on', function (): void {
    config()->set('relaticle.teams.creation_admins_only', true);

    $user = User::factory()->create();

    expect($user->can('create', Team::class))->toBeFalse();
});

it('allows a team owner to create a team when creation_admins_only is on', function (): void {
    config()->set('relaticle.teams.creation_admins_only', true);

    $user = User::factory()->withTeam()->create();

    expect($user->can('create', Team::class))->toBeTrue();
});

it('allows an admin member to create a team when creation_admins_only is on', function (): void {
    config()->set('relaticle.teams.creation_admins_only', true);

    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->create();
    $owner->currentTeam->users()->attach($member, ['role' => 'admin']);

    expect($member->fresh()->can('create', Team::class))->toBeTrue();
});

it('blocks an editor-only member from creating a team when creation_admins_only is on', function (): void {
    config()->set('relaticle.teams.creation_admins_only', true);

    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->create();
    $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

    expect($member->fresh()->can('create', Team::class))->toBeFalse();
});
