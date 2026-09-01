<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE teams AS t
            SET
                contact_creation_mode = src.contact_creation_mode,
                auto_create_companies = src.auto_create_companies
            FROM (
                SELECT DISTINCT ON (ca.team_id)
                    ca.team_id,
                    ca.contact_creation_mode,
                    ca.auto_create_companies
                FROM connected_accounts AS ca
                INNER JOIN teams AS owner ON ca.team_id = owner.id
                WHERE ca.deleted_at IS NULL
                ORDER BY ca.team_id, (ca.user_id = owner.user_id) DESC, ca.created_at ASC
            ) AS src
            WHERE t.id = src.team_id
            SQL);
    }
};
