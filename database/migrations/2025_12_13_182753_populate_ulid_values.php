<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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
};
