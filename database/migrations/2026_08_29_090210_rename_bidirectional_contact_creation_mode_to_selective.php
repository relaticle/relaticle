<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('teams')
            ->where('contact_creation_mode', 'bidirectional')
            ->update(['contact_creation_mode' => 'selective']);

        DB::statement("ALTER TABLE teams ALTER COLUMN contact_creation_mode SET DEFAULT 'selective'");
    }
};
