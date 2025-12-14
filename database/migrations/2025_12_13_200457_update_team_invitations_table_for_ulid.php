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
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->dropColumn('team_id');
        });

        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->ulid('team_id')->after('id');
        });
    }

    private function updateSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        // Get all columns except the ones we're changing
        $columns = DB::select('PRAGMA table_info(team_invitations)');
        $selectParts = [];

        foreach ($columns as $col) {
            if ($col->name !== 'team_id') {
                $selectParts[] = $col->name;
            }
        }

        $selectColumns = implode(', ', $selectParts);

        // Create new table, copy data, drop old, rename
        DB::statement("CREATE TABLE team_invitations_new AS SELECT {$selectColumns} FROM team_invitations");

        // Add the ULID column
        DB::statement('ALTER TABLE team_invitations_new ADD COLUMN team_id TEXT');

        DB::statement('DROP TABLE team_invitations');
        DB::statement('ALTER TABLE team_invitations_new RENAME TO team_invitations');

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
