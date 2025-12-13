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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->updatePivotTablesSqlite();
        } else {
            $this->updatePivotTablesStandard();
        }
    }

    private function updatePivotTablesStandard(): void
    {
        // team_user pivot table
        Schema::table('team_user', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('team_user', function (Blueprint $table): void {
            $table->ulid('team_id')->first();
            $table->ulid('user_id')->after('team_id');
        });

        // task_user pivot table
        Schema::table('task_user', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'user_id']);
        });
        Schema::table('task_user', function (Blueprint $table): void {
            $table->ulid('task_id')->first();
            $table->ulid('user_id')->after('task_id');
        });

        // taskables polymorphic pivot table
        Schema::table('taskables', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'taskable_id']);
        });
        Schema::table('taskables', function (Blueprint $table): void {
            $table->ulid('task_id')->after('id');
            $table->ulid('taskable_id')->after('task_id');
        });

        // noteables polymorphic pivot table
        Schema::table('noteables', function (Blueprint $table): void {
            $table->dropColumn(['note_id', 'noteable_id']);
        });
        Schema::table('noteables', function (Blueprint $table): void {
            $table->ulid('note_id')->after('id');
            $table->ulid('noteable_id')->after('note_id');
        });

        // imports table
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('imports', function (Blueprint $table): void {
            $table->ulid('team_id')->after('id');
            $table->ulid('user_id')->after('team_id');
        });

        // exports table
        Schema::table('exports', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'user_id']);
        });
        Schema::table('exports', function (Blueprint $table): void {
            $table->ulid('team_id')->after('id');
            $table->ulid('user_id')->after('team_id');
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
};
