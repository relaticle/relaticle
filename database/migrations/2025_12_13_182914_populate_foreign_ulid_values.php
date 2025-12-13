<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Populate Companies foreign ULIDs
        DB::statement('
            UPDATE companies
            SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = companies.team_id)
            WHERE team_id IS NOT NULL
        ');

        DB::statement('
            UPDATE companies
            SET creator_ulid = (SELECT ulid FROM users WHERE users.id = companies.creator_id)
            WHERE creator_id IS NOT NULL
        ');

        DB::statement('
            UPDATE companies
            SET account_owner_ulid = (SELECT ulid FROM users WHERE users.id = companies.account_owner_id)
            WHERE account_owner_id IS NOT NULL
        ');

        // Populate People foreign ULIDs
        DB::statement('
            UPDATE people
            SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = people.team_id)
            WHERE team_id IS NOT NULL
        ');

        DB::statement('
            UPDATE people
            SET creator_ulid = (SELECT ulid FROM users WHERE users.id = people.creator_id)
            WHERE creator_id IS NOT NULL
        ');

        DB::statement('
            UPDATE people
            SET company_ulid = (SELECT ulid FROM companies WHERE companies.id = people.company_id)
            WHERE company_id IS NOT NULL
        ');

        // Populate Opportunities foreign ULIDs
        DB::statement('
            UPDATE opportunities
            SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = opportunities.team_id)
            WHERE team_id IS NOT NULL
        ');

        DB::statement('
            UPDATE opportunities
            SET creator_ulid = (SELECT ulid FROM users WHERE users.id = opportunities.creator_id)
            WHERE creator_id IS NOT NULL
        ');

        DB::statement('
            UPDATE opportunities
            SET company_ulid = (SELECT ulid FROM companies WHERE companies.id = opportunities.company_id)
            WHERE company_id IS NOT NULL
        ');

        DB::statement('
            UPDATE opportunities
            SET contact_ulid = (SELECT ulid FROM people WHERE people.id = opportunities.contact_id)
            WHERE contact_id IS NOT NULL
        ');

        // Populate Tasks foreign ULIDs
        DB::statement('
            UPDATE tasks
            SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = tasks.team_id)
            WHERE team_id IS NOT NULL
        ');

        DB::statement('
            UPDATE tasks
            SET creator_ulid = (SELECT ulid FROM users WHERE users.id = tasks.creator_id)
            WHERE creator_id IS NOT NULL
        ');

        // Populate Notes foreign ULIDs
        DB::statement('
            UPDATE notes
            SET team_ulid = (SELECT ulid FROM teams WHERE teams.id = notes.team_id)
            WHERE team_id IS NOT NULL
        ');

        DB::statement('
            UPDATE notes
            SET creator_ulid = (SELECT ulid FROM users WHERE users.id = notes.creator_id)
            WHERE creator_id IS NOT NULL
        ');

        // Populate Users foreign ULIDs
        DB::statement('
            UPDATE users
            SET current_team_ulid = (SELECT ulid FROM teams WHERE teams.id = users.current_team_id)
            WHERE current_team_id IS NOT NULL
        ');

        // Populate Teams foreign ULIDs
        DB::statement('
            UPDATE teams
            SET user_ulid = (SELECT ulid FROM users WHERE users.id = teams.user_id)
            WHERE user_id IS NOT NULL
        ');
    }
};
