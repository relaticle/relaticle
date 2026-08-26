<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\AvatarService;
use Filament\Panel;

mutates(User::class, AvatarService::class);

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

test('user has a consistent local initial avatar', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
    ]);

    expect($user->getFilamentAvatarUrl())
        ->toStartWith('data:image/svg+xml;base64,')
        ->toBe($user->profile_photo_url)
        ->and($user->avatar)->toBe($user->profile_photo_url);
});
