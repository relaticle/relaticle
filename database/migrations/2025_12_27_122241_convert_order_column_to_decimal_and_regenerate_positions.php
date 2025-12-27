<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\Flowforge\Services\DecimalPosition;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert tasks table order_column from VARCHAR to DECIMAL
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('order_column', 20, 10)->nullable();
        });

        // Regenerate positions for tasks
        $this->regenerateTaskPositions();

        // Convert opportunities table order_column from VARCHAR to DECIMAL
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->decimal('order_column', 20, 10)->nullable();
        });

        // Regenerate positions for opportunities
        $this->regenerateOpportunityPositions();
    }

    /**
     * Regenerate positions for tasks using DecimalPosition service.
     */
    private function regenerateTaskPositions(): void
    {
        // Get all tasks with their status information, grouped by team and status
        $tasks = DB::select("
            SELECT
                t.id,
                t.team_id,
                COALESCE(cfv.integer_value, 0) as status_id
            FROM tasks t
            LEFT JOIN custom_field_values cfv ON (
                t.id = cfv.entity_id
                AND cfv.custom_field_id = (
                    SELECT id FROM custom_fields
                    WHERE code = 'status'
                    AND entity_type = 'App\\\\Models\\\\Task'
                    LIMIT 1
                )
            )
            ORDER BY t.team_id, status_id, t.created_at, t.id
        ");

        // Group tasks by team and status
        $groupedTasks = [];
        foreach ($tasks as $task) {
            $key = "{$task->team_id}_{$task->status_id}";
            $groupedTasks[$key][] = $task;
        }

        // Set positions for each group using DecimalPosition
        foreach ($groupedTasks as $tasks) {
            $this->setPositionsForGroup($tasks, 'tasks');
        }
    }

    /**
     * Regenerate positions for opportunities using DecimalPosition service.
     */
    private function regenerateOpportunityPositions(): void
    {
        // Get all opportunities with their stage information, grouped by team and stage
        $opportunities = DB::select("
            SELECT
                o.id,
                o.team_id,
                COALESCE(cfv.integer_value, 0) as stage_id
            FROM opportunities o
            LEFT JOIN custom_field_values cfv ON (
                o.id = cfv.entity_id
                AND cfv.custom_field_id = (
                    SELECT id FROM custom_fields
                    WHERE code = 'stage'
                    AND entity_type = 'App\\\\Models\\\\Opportunity'
                    LIMIT 1
                )
            )
            ORDER BY o.team_id, stage_id, o.created_at, o.id
        ");

        // Group opportunities by team and stage
        $groupedOpportunities = [];
        foreach ($opportunities as $opportunity) {
            $key = "{$opportunity->team_id}_{$opportunity->stage_id}";
            $groupedOpportunities[$key][] = $opportunity;
        }

        // Set positions for each group using DecimalPosition
        foreach ($groupedOpportunities as $opportunities) {
            $this->setPositionsForGroup($opportunities, 'opportunities');
        }
    }

    /**
     * Set positions for a group of records using DecimalPosition.
     *
     * @param  array  $records  Array of records with id property
     * @param  string  $table  Table name ('tasks' or 'opportunities')
     */
    private function setPositionsForGroup(array $records, string $table): void
    {
        if ($records === []) {
            return;
        }

        $count = count($records);

        // Generate sequential positions using DecimalPosition
        $positions = DecimalPosition::generateSequence($count);

        // Update database with new positions
        foreach ($records as $index => $record) {
            DB::table($table)
                ->where('id', $record->id)
                ->update(['order_column' => $positions[$index]]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert tasks table
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('order_column')->nullable();
        });

        // Revert opportunities table
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->string('order_column')->nullable();
        });
    }
};
