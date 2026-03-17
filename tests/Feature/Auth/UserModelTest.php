<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSocialAccount;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

mutates(User::class);

test('user has many social accounts', function () {
    $user = User::factory()->create();
    $socialAccount = UserSocialAccount::factory()->create([
        'user_id' => $user->id,
    ]);

    expect($user->socialAccounts->first())->toBeInstanceOf(UserSocialAccount::class)
        ->and($user->socialAccounts->first()->id)->toBe($socialAccount->id);
});

test('user belongs to many tasks', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $user->tasks()->attach($task);

    expect($user->tasks->first())->toBeInstanceOf(Task::class)
        ->and($user->tasks->first()->id)->toBe($task->id);
});

test('user can access tenants', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->ownedTeams()->save($team);

    $tenants = $user->getTenants(app(Panel::class)->id('app'));

    expect($tenants->count())->toBe(1)
        ->and($tenants->first()->id)->toBe($team->id);
});

test('user can access tenant', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->ownedTeams()->save($team);
    $user->currentTeam()->associate($team);
    $user->save();

    expect($user->canAccessTenant($team))->toBeTrue();
});

test('user has avatar', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
    ]);

    expect($user->getFilamentAvatarUrl())->not->toBeNull()
        ->and($user->avatar)->not->toBeNull();
});
