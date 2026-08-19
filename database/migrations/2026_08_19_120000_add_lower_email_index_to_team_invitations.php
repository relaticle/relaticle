<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PendingInvitationsForUser matches an invitation to the signed-in user with
 * `whereRaw('lower(email) = ?')`, and that runs on every app-panel page load
 * via the panel render hook. A plain index on `email` cannot serve a call
 * wrapped in `lower()`, so the lookup falls back to a sequential scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists team_invitations_lower_email_index on team_invitations (lower(email))');
    }
};
