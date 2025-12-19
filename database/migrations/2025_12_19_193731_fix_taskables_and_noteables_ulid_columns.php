<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration fixes the taskables.taskable_id and noteables.note_id/noteable_id
     * columns that were not properly converted to ULID (char(26)) during the initial
     * ULID migration. These columns are still bigint but need to be char(26) to store ULIDs.
     */
    public function up(): void
    {
        // Fix taskables.taskable_id - change from bigint to char(26)
        if ($this->columnIsBigint('taskables', 'taskable_id')) {
            Schema::table('taskables', function (Blueprint $table): void {
                $table->dropIndex('taskables_taskable_type_taskable_id_index');
            });

            Schema::table('taskables', function (Blueprint $table): void {
                $table->char('taskable_id', 26)->change();
            });

            Schema::table('taskables', function (Blueprint $table): void {
                $table->index(['taskable_type', 'taskable_id']);
            });
        }

        // Fix noteables.note_id - change from bigint to char(26)
        if ($this->columnIsBigint('noteables', 'note_id')) {
            Schema::table('noteables', function (Blueprint $table): void {
                $table->char('note_id', 26)->change();
            });
        }

        // Fix noteables.noteable_id - change from bigint to char(26)
        if ($this->columnIsBigint('noteables', 'noteable_id')) {
            Schema::table('noteables', function (Blueprint $table): void {
                $table->dropIndex('noteables_noteable_type_noteable_id_index');
            });

            Schema::table('noteables', function (Blueprint $table): void {
                $table->char('noteable_id', 26)->change();
            });

            Schema::table('noteables', function (Blueprint $table): void {
                $table->index(['noteable_type', 'noteable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't reverse this migration as it's a fix for a broken state
    }

    /**
     * Check if a column is currently a bigint type.
     */
    private function columnIsBigint(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        $columnType = Schema::getColumnType($table, $column);

        return in_array($columnType, ['bigint', 'integer', 'int8', 'int4']);
    }
};
