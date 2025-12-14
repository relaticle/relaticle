<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if already using ULID (fresh install)
        if ($this->isAlreadyUlid('users', 'id')) {
            return;
        }

        // Generate ULIDs for existing records
        $this->populateUlids('users');
        $this->populateUlids('teams');
        $this->populateUlids('companies');
        $this->populateUlids('people');
        $this->populateUlids('opportunities');
        $this->populateUlids('tasks');
        $this->populateUlids('notes');
    }

    /**
     * Populate ULIDs for existing records in a table.
     */
    private function populateUlids(string $table): void
    {
        // Process in chunks to avoid memory issues
        DB::table($table)
            ->whereNull('ulid')
            ->lazyById(100)
            ->each(function ($record) use ($table): void {
                DB::table($table)
                    ->where('id', $record->id)
                    ->update(['ulid' => (string) Str::ulid()]);
            });
    }

    /**
     * Check if a column is already using ULID (char/string type).
     */
    private function isAlreadyUlid(string $table, string $column): bool
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return false;
        }

        $columnType = Schema::getColumnType($table, $column);

        return in_array($columnType, ['string', 'char', 'varchar', 'bpchar']);
    }
};
