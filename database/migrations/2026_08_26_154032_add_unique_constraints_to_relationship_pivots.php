<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE task_user, taskables, noteables IN SHARE ROW EXCLUSIVE MODE');

            DB::statement(<<<'SQL'
                DELETE FROM task_user a
                USING task_user b
                WHERE a.task_id = b.task_id
                  AND a.user_id = b.user_id
                  AND a.id > b.id
            SQL);

            DB::statement(<<<'SQL'
                DELETE FROM taskables a
                USING taskables b
                WHERE a.task_id = b.task_id
                  AND a.taskable_type = b.taskable_type
                  AND a.taskable_id = b.taskable_id
                  AND a.id > b.id
            SQL);

            DB::statement(<<<'SQL'
                DELETE FROM noteables a
                USING noteables b
                WHERE a.note_id = b.note_id
                  AND a.noteable_type = b.noteable_type
                  AND a.noteable_id = b.noteable_id
                  AND a.id > b.id
            SQL);

            Schema::table('task_user', function (Blueprint $table): void {
                $table->unique(['task_id', 'user_id']);
            });

            Schema::table('taskables', function (Blueprint $table): void {
                $table->unique(['task_id', 'taskable_type', 'taskable_id']);
            });

            Schema::table('noteables', function (Blueprint $table): void {
                $table->unique(['note_id', 'noteable_type', 'noteable_id']);
            });
        });
    }
};
