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
        if ($this->isAlreadyUlid('notifications', 'notifiable_id')) {
            return;
        }

        // Add new ULID column for notifiable_id
        Schema::table('notifications', function (Blueprint $table): void {
            $table->char('notifiable_ulid', 26)->nullable()->after('notifiable_id');
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
                UPDATE notifications n
                SET notifiable_ulid = t.id
                FROM {$table} t
                WHERE n.notifiable_id = t.id::bigint
                AND n.notifiable_type = '{$modelClass}'
            ");
        }

        // Drop old column and rename ULID column
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('notifiable_id');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->renameColumn('notifiable_ulid', 'notifiable_id');
        });

        // Add index for morphable lookup
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['notifiable_type', 'notifiable_id']);
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
