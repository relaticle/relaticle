<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['companies', 'people', 'opportunities', 'tasks', 'notes'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            $indexName = "idx_{$table}_team_activity";

            if (Schema::hasIndex($table, $indexName)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->index(
                    ['team_id', 'deleted_at', 'creation_source', 'created_at'],
                    $indexName,
                );
            });
        }
    }
};
