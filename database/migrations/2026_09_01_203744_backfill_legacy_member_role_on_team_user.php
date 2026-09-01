<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `member` predates the admin/editor split and is not a registered Jetstream
 * role, so the members roster rendered it as a raw lowercase key. Its holders
 * already resolved to exactly Editor permissions, so renaming the value changes
 * nobody's access.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_user')
            ->where('role', 'member')
            ->update(['role' => TeamRole::Editor->value, 'updated_at' => now()]);
    }
};
