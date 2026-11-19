<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const int CHUNK_SIZE = 500;

    public function up(): void
    {
        foreach (['users', 'system_administrators'] as $table) {
            DB::table($table)
                ->whereNotNull('remember_token')
                ->eachById(
                    fn (object $row): int => DB::table($table)->where('id', $row->id)->update(['remember_token' => null]),
                    self::CHUNK_SIZE,
                );
        }
    }
};
