<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The team_user (team_id, user_id) unique constraint was verified missing
     * in production (pg_constraint check, 2026-08-18): prod executed an early
     * version of the ULID migration that dropped it without the later-added
     * recreation phase. No duplicate pairs existed at verification time; the
     * dedupe is defensive so the constraint creation cannot fail on rows that
     * land before this deploys. Idempotent: a no-op when the constraint
     * already exists.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM team_user a
            USING team_user b
            WHERE a.team_id = b.team_id
              AND a.user_id = b.user_id
              AND a.id > b.id
        SQL);

        $constraintExists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'team_user_team_id_user_id_unique' AND conrelid = 'team_user'::regclass"
        ) !== null;

        if ($constraintExists) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS team_user_team_id_user_id_unique');

        Schema::table('team_user', function (Blueprint $table): void {
            $table->unique(['team_id', 'user_id']);
        });
    }
};
