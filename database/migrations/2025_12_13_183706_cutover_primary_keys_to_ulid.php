<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite requires full table rebuild for this type of change
            $this->cutoverSqlite();
        } else {
            // PostgreSQL/MySQL can do it more efficiently
            $this->cutoverStandard();
        }
    }

    private function cutoverStandard(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = ['users', 'teams', 'companies', 'people', 'opportunities', 'tasks', 'notes'];

        foreach ($tables as $table) {
            // Step 1: Drop the primary key constraint
            Schema::table($table, function (Blueprint $table): void {
                $table->dropPrimary(['id']);
            });

            // Step 2: Drop the old integer id column
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('id');
            });

            // Step 3: Rename ulid to id
            Schema::table($table, function (Blueprint $table): void {
                $table->renameColumn('ulid', 'id');
            });

            // Step 4: Make the new id column the primary key
            Schema::table($table, function (Blueprint $table): void {
                $table->primary('id');
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    private function cutoverSqlite(): void
    {
        // For SQLite, table rebuild is required for complex ALTER operations
        // This approach: 1) Create temp table, 2) Copy data, 3) Drop old, 4) Rename

        // Disable foreign key constraints for SQLite
        DB::statement('PRAGMA foreign_keys = OFF');

        $tables = ['users', 'teams', 'companies', 'people', 'opportunities', 'tasks', 'notes'];

        foreach ($tables as $tableName) {
            // Drop any leftover temp tables from failed migrations
            DB::statement("DROP TABLE IF EXISTS {$tableName}_new");

            // Get all column names except 'id' (we'll use 'ulid' as 'id')
            $columns = DB::select("PRAGMA table_info({$tableName})");
            $columnList = collect($columns)
                ->filter(fn ($col): bool => $col->name !== 'id') // Exclude old id
                ->map(fn ($col) => $col->name)
                ->toArray();

            // Add 'ulid AS id' to beginning of column list
            $selectColumns = 'ulid AS id, '.implode(', ', $columnList);
            $insertColumns = 'id, '.implode(', ', $columnList);

            // Execute the table rebuild
            DB::statement("CREATE TABLE {$tableName}_new AS SELECT {$selectColumns} FROM {$tableName}");
            DB::statement("DROP TABLE {$tableName}");
            DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
        }

        // Re-enable foreign key constraints
        DB::statement('PRAGMA foreign_keys = ON');
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
