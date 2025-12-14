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
            $this->updateSqlite();
        } else {
            $this->updateStandard();
        }
    }

    private function updateStandard(): void
    {
        Schema::table('failed_import_rows', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'import_id']);
        });

        Schema::table('failed_import_rows', function (Blueprint $table): void {
            $table->ulid('team_id')->after('id');
            $table->ulid('import_id')->after('data');
        });
    }

    private function updateSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        // Get all columns except the ones we're changing
        $columns = DB::select('PRAGMA table_info(failed_import_rows)');
        $selectParts = [];

        foreach ($columns as $col) {
            if (! in_array($col->name, ['team_id', 'import_id'], true)) {
                $selectParts[] = $col->name;
            }
        }

        $selectColumns = implode(', ', $selectParts);

        // Create new table, copy data, drop old, rename
        DB::statement("CREATE TABLE failed_import_rows_new AS SELECT {$selectColumns} FROM failed_import_rows");

        // Add the ULID columns
        DB::statement('ALTER TABLE failed_import_rows_new ADD COLUMN team_id TEXT');
        DB::statement('ALTER TABLE failed_import_rows_new ADD COLUMN import_id TEXT');

        DB::statement('DROP TABLE failed_import_rows');
        DB::statement('ALTER TABLE failed_import_rows_new RENAME TO failed_import_rows');

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
