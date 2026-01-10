<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class SystemAdministratorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SystemAdministrator::query()->firstOrCreate(['email' => 'sysadmin@relaticle.com'], [
            'name' => 'System Administrator',
            'password' => bcrypt('password'),
            'role' => SystemAdministratorRole::SuperAdministrator,
            'email_verified_at' => now(),
        ]);
    }
}
