<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // bpchar to varchar is a function cast, so this rewrites the table under
        // ACCESS EXCLUSIVE; unbounded, one in-flight write blocks every read.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE media ALTER COLUMN model_id TYPE varchar(36)');
    }
};
