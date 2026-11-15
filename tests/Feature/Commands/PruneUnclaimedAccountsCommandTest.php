<?php

declare(strict_types=1);

use App\Console\Commands\PruneUnclaimedAccountsCommand;
use App\Models\User;

mutates(PruneUnclaimedAccountsCommand::class);

it('deletes an unverified account older than 30 days that holds nothing', function (): void {
    $unclaimed = User::factory()->unverified()->create(['created_at' => now()->subDays(31)]);

    $this->artisan('users:prune-unclaimed')
        ->expectsOutputToContain('Pruned 1 unclaimed account(s).')
        ->assertSuccessful();

    expect($unclaimed->fresh())->toBeNull();
});

it('keeps an unverified account for its first 30 days', function (): void {
    $recent = User::factory()->unverified()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('users:prune-unclaimed')->assertSuccessful();

    expect($recent->fresh())->not->toBeNull();
});

it('keeps a verified account however old it is', function (): void {
    $verified = User::factory()->create(['created_at' => now()->subYear()]);

    $this->artisan('users:prune-unclaimed')->assertSuccessful();

    expect($verified->fresh())->not->toBeNull();
});

it('keeps an old unverified account that holds something', function (Closure $give): void {
    $holder = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
    $give($holder);

    $this->artisan('users:prune-unclaimed')
        ->expectsOutputToContain('Pruned 0 unclaimed account(s).')
        ->assertSuccessful();

    expect($holder->fresh())->not->toBeNull();
})->with([
    'its own workspace' => [fn (User $user) => $user->ownedWorkspaces()->create(['name' => 'Kept Workspace', 'personal_workspace' => true])],
    'a membership' => [fn (User $user) => User::factory()->withWorkspace()->create()->ownedWorkspaces()->first()->users()->attach($user, ['role' => 'member'])],
    'a provider link' => [fn (User $user) => $user->socialAccounts()->create(['provider_name' => 'google', 'provider_id' => 'kept-google-id'])],
]);

it('takes the age from the days option', function (): void {
    $unclaimed = User::factory()->unverified()->create(['created_at' => now()->subDays(8)]);

    $this->artisan('users:prune-unclaimed', ['--days' => 7])->assertSuccessful();

    expect($unclaimed->fresh())->toBeNull();
});

it('is scheduled', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('users:prune-unclaimed')
        ->assertSuccessful();
});
