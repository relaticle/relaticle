<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rows whose lowercase form collides with another row are left untouched:
     * they are distinct accounts today and merging them is a human decision.
     * Model mutators keep every new write lowercase from here on.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users SET email = lower(email)
            WHERE email <> lower(email)
              AND NOT EXISTS (
                SELECT 1 FROM users other
                WHERE lower(other.email) = lower(users.email)
                  AND other.id <> users.id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE team_invitations SET email = lower(email)
            WHERE email <> lower(email)
              AND NOT EXISTS (
                SELECT 1 FROM team_invitations other
                WHERE other.team_id = team_invitations.team_id
                  AND lower(other.email) = lower(team_invitations.email)
                  AND other.id <> team_invitations.id
              )
        SQL);
    }
};
