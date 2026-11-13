<?php

declare(strict_types=1);

use App\Console\Commands\CreateSystemAdminCommand;
use App\Console\Concerns\ReadsStringOptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(CreateSystemAdminCommand::class, ReadsStringOptions::class);

it('creates a system administrator from its options', function (): void {
    $this->artisan('sysadmin:create', [
        '--name' => 'Ada Lovelace',
        '--email' => 'ada@example.test',
        '--password' => 'correct-horse',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(SystemAdministrator::query()->where('email', 'ada@example.test')->value('name'))->toBe('Ada Lovelace');
});

it('asks for the email when the email option is empty', function (): void {
    $this->artisan('sysadmin:create', [
        '--name' => 'Ada Lovelace',
        '--email' => '',
        '--password' => 'correct-horse',
    ])
        ->expectsQuestion('Email address', 'ada@example.test')
        ->expectsConfirmation('Create this system administrator?', 'yes')
        ->assertSuccessful();

    expect(SystemAdministrator::query()->where('email', 'ada@example.test')->exists())->toBeTrue()
        ->and(SystemAdministrator::query()->where('email', '')->exists())->toBeFalse();
});
