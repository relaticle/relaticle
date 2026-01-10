<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

final class InstallCommand extends Command
{
    protected $signature = 'relaticle:install
                            {--force : Force installation even if already configured}
                            {--env-file= : Custom path to .env file (for testing)}';

    protected $description = 'Install and configure Relaticle';

    public function handle(): int
    {
        $this->displayWelcome();

        if (! $this->shouldProceed()) {
            return self::SUCCESS;
        }

        $config = $this->getConfiguration();

        return $this->runInstallation($config);
    }

    private function displayWelcome(): void
    {
        $this->output->write(PHP_EOL.'  <fg=cyan>
 ____      _       _   _      _
|  _ \ ___| | __ _| |_(_) ___| | ___
| |_) / _ \ |/ _` | __| |/ __| |/ _ \
|  _ <  __/ | (_| | |_| | (__| |  __/
|_| \_\___|_|\__,_|\__|_|\___|_|\___|</>'.PHP_EOL.PHP_EOL);
    }

    private function shouldProceed(): bool
    {
        if (! $this->option('force') && $this->isAlreadyInstalled()) {
            warning('Relaticle appears to be already installed.');

            return confirm(
                label: 'Do you want to continue anyway?',
                default: false,
                hint: 'This may overwrite existing configuration'
            );
        }

        return true;
    }

    private function isAlreadyInstalled(): bool
    {
        return File::exists($this->envPath()) &&
               config('app.key') &&
               File::exists(public_path('storage'));
    }

    /** @return array<string, mixed> */
    private function getConfiguration(): array
    {
        $this->info('Let\'s configure your Relaticle installation...');

        $database = select(
            label: 'Which database would you like to use?',
            options: [
                'sqlite' => 'SQLite (Recommended for local development)',
                'pgsql' => 'PostgreSQL (Recommended for production)',
                'mysql' => 'MySQL/MariaDB',
            ],
            default: 'sqlite',
            hint: 'SQLite requires no additional setup'
        );

        $installDemoData = confirm(
            label: 'Install demo data?',
            default: true,
            hint: 'Includes sample companies, contacts, and more'
        );

        $createSysAdmin = confirm(
            label: 'Create system administrator account?',
            default: true,
            hint: 'You can create one later using: php artisan sysadmin:create'
        );

        return [
            'database' => $database,
            'demo_data' => $installDemoData,
            'sysadmin_user' => $createSysAdmin,
        ];
    }

    /** @param array<string, mixed> $config */
    private function runInstallation(array $config): int
    {
        /** @var array<string, callable(): bool> */
        $installationSteps = [
            'System Requirements' => $this->checkSystemRequirements(...),
            'Environment Setup' => fn (): bool => $this->setupEnvironment($config),
            'Dependencies' => $this->installDependencies(...),
            'Database' => $this->setupDatabase(...),
            'Assets' => $this->buildAssets(...),
            'Storage' => $this->setupStorage(...),
            'Demo Data' => fn (): bool => $config['demo_data'] ? $this->seedDemoData() : true,
        ];

        $this->info('🚀 Starting installation process...');

        // Process non-interactive steps with spinner
        foreach ($installationSteps as $stepName => $stepFunction) {
            $success = spin(
                callback: fn (): mixed => $stepFunction(),
                message: "Installing {$stepName}..."
            );

            if (! $success) {
                $this->newLine();
                $this->error("❌ Installation failed during: {$stepName}");
                $this->newLine();
                $this->line('<comment>Check the error messages above for more details.</comment>');
                $this->line('<comment>You can re-run the installer with --force to retry.</comment>');

                return self::FAILURE;
            }

            $this->line("   ✅ {$stepName} completed");
        }

        // Handle System Administrator creation separately (requires user interaction)
        if ($config['sysadmin_user']) {
            $this->newLine();
            $this->info('Creating System Administrator account...');

            if (! $this->createSystemAdministrator()) {
                $this->newLine();
                $this->error('❌ Installation failed during: System Administrator creation');
                $this->newLine();
                $this->line('<comment>You can create a system administrator later using: php artisan sysadmin:create</comment>');

                return self::FAILURE;
            }

            $this->line('   ✅ System Administrator created');
        }

        $this->newLine();
        $this->displaySuccessMessage($config);

        return self::SUCCESS;
    }

    private function checkSystemRequirements(): bool
    {
        $requirements = [
            'PHP 8.4+' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'Composer' => $this->commandExists('composer'),
            'Node.js' => $this->commandExists('node'),
            'NPM' => $this->commandExists('npm'),
        ];

        $extensions = [
            'pdo_sqlite', 'gd', 'bcmath', 'ctype', 'fileinfo',
            'json', 'mbstring', 'openssl', 'tokenizer', 'xml',
        ];

        foreach ($extensions as $extension) {
            $requirements["PHP {$extension}"] = extension_loaded($extension);
        }

        $failed = array_filter($requirements, fn (bool $passed): bool => ! $passed);

        if ($failed !== []) {
            $this->newLine();
            $this->error('✗ Missing system requirements:');
            foreach (array_keys($failed) as $requirement) {
                $this->line("  • {$requirement}");
            }
            $this->newLine();
            $this->line('<comment>Please install the missing requirements and try again.</comment>');

            return false;
        }

        return true;
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command} 2>/dev/null")->successful();
    }

    /** @param array<string, mixed> $config */
    private function setupEnvironment(array $config): bool
    {
        // Create .env file
        if (! File::exists($this->envPath())) {
            if (! File::exists(base_path('.env.example'))) {
                return false;
            }
            File::copy(base_path('.env.example'), $this->envPath());
        }

        // Generate app key
        if (! config('app.key')) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        // Configure database
        return $this->configureDatabaseConnection($config['database']);
    }

    private function configureDatabaseConnection(string $type): bool
    {
        $envContent = File::get($this->envPath());

        if ($type === 'sqlite') {
            if (! File::exists(database_path())) {
                File::makeDirectory(database_path(), 0755, true);
            }

            $dbPath = database_path('database.sqlite');
            if (! File::exists($dbPath)) {
                File::put($dbPath, '');
            }

            $config = [
                'DB_CONNECTION=sqlite',
                "DB_DATABASE={$dbPath}",
                'DB_HOST=',
                'DB_PORT=',
                'DB_USERNAME=',
                'DB_PASSWORD=',
            ];
        } else {
            // For non-SQLite, we'll use the existing .env values or prompt if needed
            return true;
        }

        foreach ($config as $line) {
            [$key, $value] = explode('=', $line, 2);
            $envContent = preg_replace("/^{$key}=.*/m", $line, (string) $envContent);
        }

        File::put($this->envPath(), $envContent);

        return true;
    }

    private function installDependencies(): bool
    {
        $composerResult = Process::run('composer install --no-interaction --prefer-dist --optimize-autoloader');
        if (! $composerResult->successful()) {
            $this->error('Composer install failed:');
            $this->line($composerResult->errorOutput());

            return false;
        }

        $npmResult = Process::run('npm ci --silent');
        if (! $npmResult->successful()) {
            $this->error('NPM install failed:');
            $this->line($npmResult->errorOutput());

            return false;
        }

        return true;
    }

    private function setupDatabase(): bool
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            return true;
        } catch (Throwable $e) {
            $this->error('Database migration failed:');
            $this->line($e->getMessage());

            return false;
        }
    }

    private function buildAssets(): bool
    {
        $result = Process::run('npm run build');

        if (! $result->successful()) {
            $this->error('Asset compilation failed:');
            $this->line($result->errorOutput());

            return false;
        }

        return true;
    }

    private function setupStorage(): bool
    {
        try {
            Artisan::call('storage:link', ['--force' => true]);

            return true;
        } catch (Throwable $e) {
            $this->error('Storage link failed:');
            $this->line($e->getMessage());

            return false;
        }
    }

    private function seedDemoData(): bool
    {
        try {
            // When using --force, we might be re-running installation
            // Use migrate:fresh to reset the database cleanly
            if ($this->option('force')) {
                Artisan::call('migrate:fresh', ['--force' => true]);
            }

            Artisan::call('db:seed', ['--force' => true]);

            return true;
        } catch (Throwable $e) {
            $this->error('Demo data seeding failed:');
            $this->line($e->getMessage());

            return false;
        }
    }

    private function createSystemAdministrator(): bool
    {
        try {
            // Check if system administrator already exists
            if (SystemAdministrator::query()->exists()) {
                $this->warn('A System Administrator already exists.');
                $overwrite = confirm(
                    label: 'Do you want to create another one?',
                    default: false,
                    hint: 'You can have multiple system administrators'
                );

                if (! $overwrite) {
                    return true;
                }
            }

            $name = text(
                label: 'System Administrator name',
                default: 'System Admin',
                required: true
            );

            $email = text(
                label: 'System Administrator email address',
                default: 'sysadmin@relaticle.local',
                required: true,
                validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address'
            );

            // Check if email already exists
            if (SystemAdministrator::query()->where('email', $email)->exists()) {
                $this->error("A System Administrator with email '{$email}' already exists.");

                return false;
            }

            $password = text(
                label: 'System Administrator password (min. 8 characters)',
                default: 'password123',
                required: true,
                validate: fn (string $value): ?string => strlen($value) >= 8 ? null : 'Password must be at least 8 characters'
            );

            // Create the system administrator
            SystemAdministrator::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
                'role' => SystemAdministratorRole::SuperAdministrator,
            ]);

            $this->info('  System Administrator created successfully!');
            $this->line("  Email: {$email}");
            $this->line('  Access panel at: /sysadmin');

            return true;
        } catch (Throwable $e) {
            $this->error('System Administrator creation failed:');
            $this->line($e->getMessage());

            return false;
        }
    }

    /** @param array<string, mixed> $config */
    private function displaySuccessMessage(array $config): void
    {
        $this->newLine();

        $this->info('🎉 Relaticle installed successfully!');

        $this->newLine();
        $this->line('  <options=bold>Start all development services:</>');
        $this->line('  composer run dev');
        $this->newLine();

        $this->line('  <options=bold>Your application:</>');
        $this->line('  http://localhost:8000');

        if ($config['sysadmin_user'] ?? false) {
            $this->newLine();
            $this->line('  <options=bold>System Admin panel:</>');
            $this->line('  http://localhost:8000/sysadmin');
        }

        if ($config['demo_data']) {
            $this->newLine();
            $this->line('  <options=bold>Demo data included:</>');
            $this->line('  • Sample companies and contacts');
            $this->line('  • Example opportunities and tasks');
            $this->line('  • Pre-configured custom fields');
        }

        $this->newLine();
        $this->line('  <options=bold>Development services:</>');
        $this->line('  • Laravel development server');
        $this->line('  • Vite asset watcher with HMR');
        $this->line('  • Queue worker (Horizon)');
        $this->line('  • Real-time logs (Pail)');

        $this->newLine();
        $this->line('  Documentation: https://relaticle.com/documentation');
        $this->newLine();

        $this->info('Happy CRM-ing! 🚀');
    }

    private function envPath(): string
    {
        return $this->option('env-file') ?: base_path('.env');
    }
}
