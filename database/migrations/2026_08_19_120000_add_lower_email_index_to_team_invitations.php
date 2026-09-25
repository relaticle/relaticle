<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pending-invitation lookup runs on every app-panel page load and matches
 * on lower(email), which a plain index on email cannot serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists team_invitations_lower_email_index on team_invitations (lower(email))');
    }
};
