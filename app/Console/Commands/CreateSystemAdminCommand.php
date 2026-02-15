<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class CreateSystemAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sysadmin:create
                            {--name= : The name of the system administrator}
                            {--email= : The email address of the system administrator}
                            {--password= : The password for the system administrator}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new system administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Create System Administrator Account');
        $this->line('────────────────────────────────────');
        $this->newLine();

        try {
            // Check if any system administrators exist
            $existingCount = SystemAdministrator::query()->count();
            if ($existingCount > 0) {
                $this->warn("There are currently {$existingCount} system administrator(s).");

                if (! $this->option('no-interaction')) {
                    $continue = confirm(
                        label: 'Do you want to create another system administrator?',
                        default: true
                    );

                    if (! $continue) {
                        $this->info('Operation cancelled.');

                        return self::SUCCESS;
                    }
                }
            }

            // Get administrator details
            $name = $this->option('name') ?? text(
                label: 'Name',
                placeholder: 'John Doe',
                required: true,
                validate: fn (string $value): ?string => strlen($value) < 2 ? 'Name must be at least 2 characters' : null
            );

            $email = $this->option('email') ?? text(
                label: 'Email address',
                placeholder: 'admin@example.com',
                required: true,
                validate: function (string $value): ?string {
                    if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        return 'Please enter a valid email address';
                    }

                    if (SystemAdministrator::query()->where('email', $value)->exists()) {
                        return "An administrator with email '{$value}' already exists";
                    }

                    return null;
                }
            );

            // Validate email from command option
            if ($this->option('email')) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->error('Invalid email address provided.');

                    return self::FAILURE;
                }

                if (SystemAdministrator::query()->where('email', $email)->exists()) {
                    $this->error("An administrator with email '{$email}' already exists.");

                    return self::FAILURE;
                }
            }

            $password = $this->option('password') ?? password(
                label: 'Password',
                placeholder: 'Minimum 8 characters',
                required: true,
                validate: fn (string $value): ?string => strlen($value) < 8 ? 'Password must be at least 8 characters' : null
            );

            // Validate password from command option
            if ($this->option('password') && strlen($password) < 8) {
                $this->error('Password must be at least 8 characters.');

                return self::FAILURE;
            }

            // Show summary
            if (! $this->option('no-interaction') && ! ($this->option('name') && $this->option('email') && $this->option('password'))) {
                $this->newLine();
                $this->info('Summary:');
                $this->line("  Name:  {$name}");
                $this->line("  Email: {$email}");
                $this->line('  Role:  Super Administrator');
                $this->newLine();

                $confirmed = confirm(
                    label: 'Create this system administrator?',
                    default: true
                );

                if (! $confirmed) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            }

            // Create the system administrator
            $admin = SystemAdministrator::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
                'role' => SystemAdministratorRole::SuperAdministrator,
            ]);

            $this->newLine();
            $this->info('✅ System Administrator created successfully!');
            $this->newLine();
            $this->line('  Details:');
            $this->line("  ├─ Name:  {$admin->name}");
            $this->line("  ├─ Email: {$admin->email}");
            $this->line('  ├─ Role:  Super Administrator');
            $this->line('  └─ Panel: /sysadmin');
            $this->newLine();
            $this->line('  You can now log in at: '.url('/sysadmin'));
            $this->newLine();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to create system administrator:');
            $this->line($e->getMessage());

            if ($this->option('verbose')) {
                $this->newLine();
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
