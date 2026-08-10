<?php

declare(strict_types=1);

use App\Console\Commands\RefreshDemoAccountCommand;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;

mutates(RefreshDemoAccountCommand::class);

it('creates the demo account on first run', function (): void {
    $this->artisan('app:refresh-demo-account')->assertSuccessful();

    $user = User::query()->where('email', 'demo@relaticle.com')->firstOrFail();

    expect($user->two_factor_secret)->toBeNull();
    expect($user->personalTeam())->not->toBeNull();
    expect(Company::query()->where('team_id', $user->personalTeam()->getKey())->count())->toBeGreaterThan(0);
});

it('is idempotent and resets demo data on re-run', function (): void {
    $this->artisan('app:refresh-demo-account')->assertSuccessful();
    $this->artisan('app:refresh-demo-account')->assertSuccessful();

    expect(User::query()->where('email', 'demo@relaticle.com')->count())->toBe(1);
});

it('cleans pivot rows for the demo team on re-run', function (): void {
    $this->artisan('app:refresh-demo-account')->assertSuccessful();

    $user = User::query()->where('email', 'demo@relaticle.com')->firstOrFail();
    $teamId = $user->personalTeam()->getKey();

    // Synthesize a pivot row that would orphan on the next refresh.
    $task = Task::query()->where('team_id', $teamId)->firstOrFail();
    $company = Company::query()->where('team_id', $teamId)->firstOrFail();

    DB::table('taskables')->insert([
        'task_id' => $task->getKey(),
        'taskable_type' => $company->getMorphClass(),
        'taskable_id' => $company->getKey(),
    ]);

    $this->artisan('app:refresh-demo-account')->assertSuccessful();

    // After refresh: no rows in taskables reference task_ids that no longer exist.
    $danglingTaskables = DB::table('taskables')
        ->whereNotIn('task_id', Task::query()->pluck('id'))
        ->count();

    expect($danglingTaskables)->toBe(0);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('app:refresh-demo-account')
        ->expectsOutputToContain('Refusing to run in production')
        ->assertFailed();

    expect(User::query()->where('email', 'demo@relaticle.com')->exists())->toBeFalse();
});
