<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The email privacy scope plans above jit_above_cost, so Postgres JIT-compiled
        // every inbox read: 230ms with JIT, 60ms without, measured on production.
        if (! $this->applicationRoleOwnsDatabase()) {
            return;
        }

        DB::unprepared("DO $$ BEGIN EXECUTE format('ALTER DATABASE %I SET jit = off', current_database()); END $$");
    }

    private function applicationRoleOwnsDatabase(): bool
    {
        return (bool) DB::scalar('SELECT pg_get_userbyid(datdba) = current_user FROM pg_database WHERE datname = current_database()');
    }
};
