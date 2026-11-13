<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspaces')
            ->where('default_email_sharing_tier', 'full')
            ->eachById(function (object $workspace): void {
                DB::table('connected_accounts')
                    ->where('workspace_id', $workspace->id)
                    ->whereNull('sharing_tier')
                    ->update(['sharing_tier' => 'full']);

                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->update(['default_email_sharing_tier' => 'subject']);
            });
    }
};
