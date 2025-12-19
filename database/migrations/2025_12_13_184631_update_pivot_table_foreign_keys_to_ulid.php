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
            $this->updatePivotTablesSqlite();
        } else {
            $this->updatePivotTablesStandard();
        }
    }

    private function updatePivotTablesStandard(): void
    {
        // Note: We need to preserve data, so we add new ULID columns,
        // populate them from the existing columns, then drop the old ones.

        // team_user pivot table
        Schema::table('team_user', function (Blueprint $table): void {
            $table->char('team_ulid', 26)->nullable()->after('team_id');
            $table->char('user_ulid', 26)->nullable()->after('user_id');
        });
        DB::statement('UPDATE team_user SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = team_user.team_id)');
        DB::statement('UPDATE team_user SET user_ulid = (SELECT ulid FROM users WHERE users.id = team_user.user_id)');
        Schema::table('team_user', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('team_user', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('user_ulid', 'user_id');
        });

        // task_user pivot table
        Schema::table('task_user', function (Blueprint $table): void {
            $table->char('task_ulid', 26)->nullable()->after('task_id');
            $table->char('user_ulid', 26)->nullable()->after('user_id');
        });
        DB::statement('UPDATE task_user SET task_ulid = (SELECT ulid FROM tasks WHERE tasks.id = task_user.task_id)');
        DB::statement('UPDATE task_user SET user_ulid = (SELECT ulid FROM users WHERE users.id = task_user.user_id)');
        Schema::table('task_user', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'user_id']);
        });
        Schema::table('task_user', function (Blueprint $table): void {
            $table->renameColumn('task_ulid', 'task_id');
            $table->renameColumn('user_ulid', 'user_id');
        });

        // taskables polymorphic pivot table
        Schema::table('taskables', function (Blueprint $table): void {
            $table->char('task_ulid', 26)->nullable()->after('task_id');
            $table->char('taskable_ulid', 26)->nullable()->after('taskable_id');
        });
        DB::statement('UPDATE taskables SET task_ulid = (SELECT ulid FROM tasks WHERE tasks.id = taskables.task_id)');
        DB::statement('UPDATE taskables SET taskable_ulid = CASE taskable_type
            WHEN \'App\\\\Models\\\\Company\' THEN (SELECT ulid FROM companies WHERE companies.id = taskables.taskable_id)
            WHEN \'App\\\\Models\\\\People\' THEN (SELECT ulid FROM people WHERE people.id = taskables.taskable_id)
            WHEN \'App\\\\Models\\\\Opportunity\' THEN (SELECT ulid FROM opportunities WHERE opportunities.id = taskables.taskable_id)
        END');
        Schema::table('taskables', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'taskable_id']);
        });
        Schema::table('taskables', function (Blueprint $table): void {
            $table->renameColumn('task_ulid', 'task_id');
            $table->renameColumn('taskable_ulid', 'taskable_id');
        });

        // noteables polymorphic pivot table
        Schema::table('noteables', function (Blueprint $table): void {
            $table->char('note_ulid', 26)->nullable()->after('note_id');
            $table->char('noteable_ulid', 26)->nullable()->after('noteable_id');
        });
        DB::statement('UPDATE noteables SET note_ulid = (SELECT ulid FROM notes WHERE notes.id = noteables.note_id)');
        DB::statement('UPDATE noteables SET noteable_ulid = CASE noteable_type
            WHEN \'App\\\\Models\\\\Company\' THEN (SELECT ulid FROM companies WHERE companies.id = noteables.noteable_id)
            WHEN \'App\\\\Models\\\\People\' THEN (SELECT ulid FROM people WHERE people.id = noteables.noteable_id)
            WHEN \'App\\\\Models\\\\Opportunity\' THEN (SELECT ulid FROM opportunities WHERE opportunities.id = noteables.noteable_id)
        END');
        Schema::table('noteables', function (Blueprint $table): void {
            $table->dropColumn(['note_id', 'noteable_id']);
        });
        Schema::table('noteables', function (Blueprint $table): void {
            $table->renameColumn('note_ulid', 'note_id');
            $table->renameColumn('noteable_ulid', 'noteable_id');
        });

        // imports table
        Schema::table('imports', function (Blueprint $table): void {
            $table->char('team_ulid', 26)->nullable()->after('team_id');
            $table->char('user_ulid', 26)->nullable()->after('user_id');
        });
        DB::statement('UPDATE imports SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = imports.team_id)');
        DB::statement('UPDATE imports SET user_ulid = (SELECT ulid FROM users WHERE users.id = imports.user_id)');
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('imports', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('user_ulid', 'user_id');
        });

        // exports table
        Schema::table('exports', function (Blueprint $table): void {
            $table->char('team_ulid', 26)->nullable()->after('team_id');
            $table->char('user_ulid', 26)->nullable()->after('user_id');
        });
        DB::statement('UPDATE exports SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = exports.team_id)');
        DB::statement('UPDATE exports SET user_ulid = (SELECT ulid FROM users WHERE users.id = exports.user_id)');
        Schema::table('exports', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('exports', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('user_ulid', 'user_id');
        });
    }

    private function updatePivotTablesSqlite(): void
    {
        // For SQLite, use the table rebuild approach
        DB::statement('PRAGMA foreign_keys = OFF');

        $pivotTables = [
            'team_user' => ['team_id', 'user_id'],
            'task_user' => ['task_id', 'user_id'],
            'taskables' => ['task_id', 'taskable_id'],
            'noteables' => ['note_id', 'noteable_id'],
            'imports' => ['team_id', 'user_id'],
            'exports' => ['team_id', 'user_id'],
        ];

        foreach ($pivotTables as $tableName => $ulidColumns) {
            // Get all columns
            $columns = DB::select("PRAGMA table_info({$tableName})");
            $allColumns = collect($columns)->map(fn ($col) => $col->name)->toArray();

            // Build SELECT with integer foreign keys excluded
            $selectParts = [];
            foreach ($allColumns as $columnName) {
                if (in_array($columnName, $ulidColumns, true)) {
                    // Skip - we're not copying these integer columns
                    continue;
                }
                $selectParts[] = $columnName;
            }

            $selectColumns = implode(', ', $selectParts);
            $insertColumns = implode(', ', $selectParts);

            // Create new table, copy data, drop old, rename
            DB::statement("CREATE TABLE {$tableName}_new AS SELECT {$selectColumns} FROM {$tableName}");

            // Add the ULID columns
            foreach ($ulidColumns as $columnName) {
                DB::statement("ALTER TABLE {$tableName}_new ADD COLUMN {$columnName} TEXT");
            }

            DB::statement("DROP TABLE {$tableName}");
            DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Check if a column is already using ULID (char/string type).
     */
    private function isAlreadyUlid(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        $columnType = Schema::getColumnType($table, $column);

        return in_array($columnType, ['string', 'char', 'varchar', 'bpchar']);
    }
};
