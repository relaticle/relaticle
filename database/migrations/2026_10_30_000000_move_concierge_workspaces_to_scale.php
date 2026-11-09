<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspaces')
            ->where('plan', 'concierge')
            ->update(['plan' => 'scale', 'updated_at' => now()]);
    }
};
