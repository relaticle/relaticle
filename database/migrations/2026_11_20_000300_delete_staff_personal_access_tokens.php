<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->whereIn('tokenable_type', ['system_administrator', SystemAdministrator::class])
            ->delete();
    }
};
