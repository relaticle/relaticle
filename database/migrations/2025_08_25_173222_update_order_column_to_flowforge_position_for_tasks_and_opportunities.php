<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\Flowforge\Services\Rank;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update tasks table
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->flowforgePositionColumn('order_column');
        });

        // Set proper order_column values for existing tasks
        $this->setTaskOrderColumns();

        // Update opportunities table
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->flowforgePositionColumn('order_column');
        });

        // Set proper order_column values for existing opportunities
        $this->setOpportunityOrderColumns();
    }

    /**
     * Set proper order_column values for tasks grouped by team and status.
     */
    private function setTaskOrderColumns(): void
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
            ORDER BY t.team_id, status_id, t.id
        ");

        // Group tasks by team and status
        $groupedTasks = [];
        foreach ($tasks as $task) {
            $key = "{$task->team_id}_{$task->status_id}";
            $groupedTasks[$key][] = $task;
        }

        // Set positions for each group
        foreach ($groupedTasks as $tasks) {
            $this->setPositionsForGroup($tasks, 'tasks');
        }
    }

    /**
     * Set proper order_column values for opportunities grouped by team and stage.
     */
    private function setOpportunityOrderColumns(): void
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
            ORDER BY o.team_id, stage_id, o.id
        ");

        // Group opportunities by team and stage
        $groupedOpportunities = [];
        foreach ($opportunities as $opportunity) {
            $key = "{$opportunity->team_id}_{$opportunity->stage_id}";
            $groupedOpportunities[$key][] = $opportunity;
        }

        // Set positions for each group
        foreach ($groupedOpportunities as $opportunities) {
            $this->setPositionsForGroup($opportunities, 'opportunities');
        }
    }

    /**
     * Set Flowforge position values for a group of records.
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
        $positions = [];

        if ($count === 1) {
            // Single record: use empty sequence position
            $positions[] = [
                'id' => $records[0]->id,
                'position' => Rank::forEmptySequence()->get(),
            ];
        } else {
            // Multiple records: generate evenly distributed positions
            $positions[] = [
                'id' => $records[0]->id,
                'position' => Rank::forEmptySequence()->get(),
            ];

            $prevRank = Rank::forEmptySequence();

            for ($i = 1; $i < $count; $i++) {
                $nextRank = Rank::after($prevRank);
                $positions[] = [
                    'id' => $records[$i]->id,
                    'position' => $nextRank->get(),
                ];
                $prevRank = $nextRank;
            }
        }

        // Update the database
        foreach ($positions as $position) {
            DB::table($table)
                ->where('id', $position['id'])
                ->update(['order_column' => $position['position']]);
        }
    }
};
