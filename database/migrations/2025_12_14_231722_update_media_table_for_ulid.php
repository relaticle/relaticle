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
        if ($this->isAlreadyUlid('media', 'model_id')) {
            return;
        }

        // Add new ULID column for model_id
        Schema::table('media', function (Blueprint $table): void {
            $table->char('model_ulid', 26)->nullable()->after('model_id');
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

        foreach ($ulidTables as $table => $modelClass) {
            DB::statement("
                UPDATE media m
                SET model_ulid = t.id
                FROM {$table} t
                WHERE m.model_id = t.id::bigint
                AND m.model_type = '{$modelClass}'
            ");
        }

        // Drop old column and rename ULID column
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('model_id');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->renameColumn('model_ulid', 'model_id');
        });

        // Add index for morphable lookup
        Schema::table('media', function (Blueprint $table): void {
            $table->index(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible
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
