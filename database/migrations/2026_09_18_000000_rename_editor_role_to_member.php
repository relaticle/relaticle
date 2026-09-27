<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 2026_09_01_203744 renamed a legacy `member` key to `editor`. This reverses that
// naming after the role was relabelled; running both leaves `member`.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspace_user')
            ->where('role', 'editor')
            ->eachById(fn (object $row) => DB::table('workspace_user')
                ->where('id', $row->id)
                ->update(['role' => 'member', 'updated_at' => now()]));

        DB::table('workspace_invitations')
            ->where('role', 'editor')
            ->eachById(fn (object $row) => DB::table('workspace_invitations')
                ->where('id', $row->id)
                ->update(['role' => 'member', 'updated_at' => now()]));

        DB::table('workspaces')
            ->where('invite_link_default_role', 'editor')
            ->eachById(fn (object $row) => DB::table('workspaces')
                ->where('id', $row->id)
                ->update(['invite_link_default_role' => 'member', 'updated_at' => now()]));

        DB::statement("ALTER TABLE workspaces ALTER COLUMN invite_link_default_role SET DEFAULT 'member'");

        // A proposal created just before deploy carries the old key inside
        // action_data.records[*].role, so it is retired rather than rewritten.
        DB::table('pending_actions')
            ->where('entity_type', 'workspace_invitations')
            ->where('status', 'pending')
            ->eachById(fn (object $row) => DB::table('pending_actions')
                ->where('id', $row->id)
                ->update(['status' => 'superseded', 'resolved_at' => now(), 'updated_at' => now()]));
    }
};
