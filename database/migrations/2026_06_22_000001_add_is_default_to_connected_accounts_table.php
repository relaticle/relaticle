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
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('display_name');
        });

        // Guarantee at most one default account per user within a team. Scoped to
        // live rows so a soft-deleted default never blocks promoting a new one.
        DB::statement(
            'CREATE UNIQUE INDEX connected_accounts_one_default_per_user_team '.
            'ON connected_accounts (user_id, team_id) '.
            'WHERE is_default = true AND deleted_at IS NULL'
        );

        // Backfill: make the oldest live account the default for each user/team
        // that has connections but no default yet.
        DB::statement(<<<'SQL'
            UPDATE connected_accounts AS ca
            SET is_default = true
            FROM (
                SELECT DISTINCT ON (user_id, team_id) id
                FROM connected_accounts
                WHERE deleted_at IS NULL
                ORDER BY user_id, team_id, created_at, id
            ) AS oldest
            WHERE ca.id = oldest.id
              AND NOT EXISTS (
                  SELECT 1 FROM connected_accounts d
                  WHERE d.user_id = ca.user_id
                    AND d.team_id = ca.team_id
                    AND d.is_default = true
                    AND d.deleted_at IS NULL
              )
        SQL);
    }
};
