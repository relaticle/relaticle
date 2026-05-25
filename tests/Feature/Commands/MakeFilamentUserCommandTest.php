<?php

declare(strict_types=1);

use App\Console\Commands\MakeFilamentUserCommand;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(MakeFilamentUserCommand::class);

it('overrides the make:filament-user signature so admins skip the verification dance after a fresh self-host install', function (): void {
    $registered = collect(Artisan::all())->first(
        fn ($command, string $name): bool => $name === 'make:filament-user',
    );

    expect($registered)->toBeInstanceOf(MakeFilamentUserCommand::class);
});

it('marks the user verified and dispatches the Verified event so downstream listeners (Mailcoach sync, etc.) fire', function (): void {
    Event::fake([Verified::class]);

    $this->artisan('make:filament-user', [
        '--name' => 'Admin',
        '--email' => 'admin@example.com',
        '--password' => 'password123',
        '--panel' => 'app',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
});

it('creates a system administrator with the SuperAdministrator role when targeting the sysadmin panel', function (): void {
    $this->artisan('make:filament-user', [
        '--name' => 'SysAdmin',
        '--email' => 'sysadmin@example.com',
        '--password' => 'password123',
        '--panel' => 'sysadmin',
    ])->assertSuccessful();

    $admin = SystemAdministrator::query()->where('email', 'sysadmin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(SystemAdministratorRole::SuperAdministrator)
        ->and($admin->hasVerifiedEmail())->toBeTrue();
});
