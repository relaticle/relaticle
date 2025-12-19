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
        if ($this->isAlreadyUlid('ai_summaries', 'summarizable_id')) {
            return;
        }

        // Add new ULID column for summarizable_id
        Schema::table('ai_summaries', function (Blueprint $table): void {
            $table->char('summarizable_ulid', 26)->nullable()->after('summarizable_id');
        });

        // Populate ULID values by joining with ULID tables
        $ulidTables = [
            'users' => 'App\\Models\\User',
            'teams' => 'App\\Models\\Team',
            'companies' => 'App\\Models\\Company',
            'tasks' => 'App\\Models\\Task',
            'notes' => 'App\\Models\\Note',
            'people' => 'App\\Models\\People',
            'opportunities' => 'App\\Models\\Opportunity',
        ];

        if (DB::getDriverName() === 'sqlite') {
            // SQLite: Populate ULID values with case statements for each model
            foreach ($ulidTables as $table => $modelClass) {
                DB::statement("
                    UPDATE ai_summaries
                    SET summarizable_ulid = (
                        SELECT t.id
                        FROM {$table} t
                        WHERE ai_summaries.summarizable_id = CAST(t.id AS INTEGER)
                        LIMIT 1
                    )
                    WHERE summarizable_type = '{$modelClass}'
                    AND EXISTS (
                        SELECT 1
                        FROM {$table} t
                        WHERE ai_summaries.summarizable_id = CAST(t.id AS INTEGER)
                    )
                ");
            }

            // SQLite: Rebuild table to drop old column
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE ai_summaries_new AS SELECT id, summarizable_ulid, summarizable_type, team_id, summary, created_at, updated_at FROM ai_summaries');
            DB::statement('DROP TABLE ai_summaries');
            DB::statement('ALTER TABLE ai_summaries_new RENAME TO ai_summaries');
            DB::statement('ALTER TABLE ai_summaries RENAME COLUMN summarizable_ulid TO summarizable_id');
            DB::statement('CREATE INDEX ai_summaries_summarizable_type_summarizable_id_index ON ai_summaries(summarizable_type, summarizable_id)');
            DB::statement('CREATE UNIQUE INDEX ai_summaries_summarizable_type_summarizable_id_team_id_unique ON ai_summaries(summarizable_type, summarizable_id, team_id)');
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            // PostgreSQL/MySQL: Populate ULIDs
            foreach ($ulidTables as $table => $modelClass) {
                DB::statement("
                    UPDATE ai_summaries a
                    SET summarizable_ulid = t.id
                    FROM {$table} t
                    WHERE a.summarizable_id = t.id::bigint
                    AND a.summarizable_type = '{$modelClass}'
                ");
            }

            // Drop old column and rename ULID column
            Schema::table('ai_summaries', function (Blueprint $table): void {
                $table->dropColumn('summarizable_id');
            });

            Schema::table('ai_summaries', function (Blueprint $table): void {
                $table->renameColumn('summarizable_ulid', 'summarizable_id');
            });

            // Add index for morphable lookup
            Schema::table('ai_summaries', function (Blueprint $table): void {
                $table->index(['summarizable_type', 'summarizable_id']);
            });
        }
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
