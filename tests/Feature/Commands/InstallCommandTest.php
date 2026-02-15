<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function createTempEnvFile(): string
{
    $tempEnv = sys_get_temp_dir().'/relaticle-test-'.uniqid().'.env';
    File::copy(base_path('.env.example'), $tempEnv);

    return $tempEnv;
}

it('completes installation without demo data and system admin', function (): void {
    $tempEnv = createTempEnvFile();

    $this->artisan('relaticle:install', [
        '--force' => true,
        '--env-file' => $tempEnv,
    ])
        ->expectsChoice('Which database would you like to use?', 'sqlite', [
            'sqlite' => 'SQLite (Recommended for local development)',
            'pgsql' => 'PostgreSQL (Recommended for production)',
            'mysql' => 'MySQL/MariaDB',
        ])
        ->expectsConfirmation('Install demo data?', 'no')
        ->expectsConfirmation('Create system administrator account?', 'no')
        ->expectsOutputToContain('Starting installation process')
        ->expectsOutputToContain('System Requirements completed')
        ->expectsOutputToContain('Environment Setup completed')
        ->expectsOutputToContain('Database completed')
        ->expectsOutputToContain('Relaticle installed successfully!')
        ->assertSuccessful();

    // Verify .env was modified in temp file
    expect(File::get($tempEnv))->toContain('DB_CONNECTION=sqlite');

    File::delete($tempEnv);
});

it('completes installation with demo data but no system admin', function (): void {
    $this->markTestSkipped('Demo data seeding test needs investigation');

    $tempEnv = createTempEnvFile();

    $this->artisan('relaticle:install', [
        '--force' => true,
        '--env-file' => $tempEnv,
    ])
        ->expectsChoice('Which database would you like to use?', 'sqlite', [
            'sqlite' => 'SQLite (Recommended for local development)',
            'pgsql' => 'PostgreSQL (Recommended for production)',
            'mysql' => 'MySQL/MariaDB',
        ])
        ->expectsConfirmation('Install demo data?', 'yes')
        ->expectsConfirmation('Create system administrator account?', 'no')
        ->expectsOutputToContain('Starting installation process')
        ->expectsOutputToContain('Demo Data completed')
        ->expectsOutputToContain('Relaticle installed successfully!')
        ->assertExitCode(0);

    File::delete($tempEnv);
});

it('creates system administrator when requested', function (): void {
    $tempEnv = createTempEnvFile();

    // Clear any existing system administrators
    \Relaticle\SystemAdmin\Models\SystemAdministrator::query()->delete();

    $this->artisan('relaticle:install', [
        '--force' => true,
        '--env-file' => $tempEnv,
    ])
        ->expectsChoice('Which database would you like to use?', 'sqlite', [
            'sqlite' => 'SQLite (Recommended for local development)',
            'pgsql' => 'PostgreSQL (Recommended for production)',
            'mysql' => 'MySQL/MariaDB',
        ])
        ->expectsConfirmation('Install demo data?', 'no')
        ->expectsConfirmation('Create system administrator account?', 'yes')
        ->expectsQuestion('System Administrator name', 'Test Admin')
        ->expectsQuestion('System Administrator email address', 'test@example.com')
        ->expectsQuestion('System Administrator password (min. 8 characters)', 'password123')
        ->expectsOutputToContain('Creating System Administrator account')
        ->expectsOutputToContain('System Administrator created')
        ->expectsOutputToContain('Relaticle installed successfully!')
        ->assertSuccessful();

    // Verify the system administrator was created
    expect(\Relaticle\SystemAdmin\Models\SystemAdministrator::where('email', 'test@example.com')->exists())->toBeTrue();

    File::delete($tempEnv);
});

it('skips system admin creation if one already exists', function (): void {
    $tempEnv = createTempEnvFile();

    // Ensure a system administrator exists
    \Relaticle\SystemAdmin\Models\SystemAdministrator::firstOrCreate(
        ['email' => 'existing@example.com'],
        [
            'name' => 'Existing Admin',
            'password' => bcrypt('password'),
            'role' => \Relaticle\SystemAdmin\Enums\SystemAdministratorRole::SuperAdministrator,
            'email_verified_at' => now(),
        ]
    );

    $this->artisan('relaticle:install', [
        '--force' => true,
        '--env-file' => $tempEnv,
    ])
        ->expectsChoice('Which database would you like to use?', 'sqlite', [
            'sqlite' => 'SQLite (Recommended for local development)',
            'pgsql' => 'PostgreSQL (Recommended for production)',
            'mysql' => 'MySQL/MariaDB',
        ])
        ->expectsConfirmation('Install demo data?', 'no')
        ->expectsConfirmation('Create system administrator account?', 'yes')
        ->expectsConfirmation('Do you want to create another one?', 'no')
        ->expectsOutputToContain('A System Administrator already exists')
        ->expectsOutputToContain('System Administrator created')
        ->expectsOutputToContain('Relaticle installed successfully!')
        ->assertSuccessful();

    File::delete($tempEnv);
});
