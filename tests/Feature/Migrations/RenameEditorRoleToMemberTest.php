<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function runRenameEditorRoleToMemberMigration(): void
{
    $migration = require database_path('migrations/2026_09_18_000000_rename_editor_role_to_member.php');
    $migration->up();
}

test('renames stored editor roles to member across every column that holds one', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->create();
    $admin = User::factory()->create();

    $workspace->users()->attach($member, ['role' => 'editor']);
    $workspace->users()->attach($admin, ['role' => 'admin']);

    DB::table('workspaces')->where('id', $workspace->getKey())->update(['invite_link_default_role' => 'editor']);

    DB::table('workspace_invitations')->insert([
        'id' => (string) Str::ulid(),
        'workspace_id' => $workspace->getKey(),
        'email' => 'invited@example.test',
        'role' => 'editor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRenameEditorRoleToMemberMigration();

    expect(DB::table('workspace_user')->where('user_id', $member->getKey())->value('role'))->toBe('member')
        ->and(DB::table('workspace_user')->where('user_id', $admin->getKey())->value('role'))->toBe('admin')
        ->and(DB::table('workspaces')->where('id', $workspace->getKey())->value('invite_link_default_role'))->toBe('member')
        ->and(DB::table('workspace_invitations')->where('email', 'invited@example.test')->value('role'))->toBe('member');
});

test('moves the invite link default so a new workspace stores member', function (): void {
    runRenameEditorRoleToMemberMigration();

    $default = DB::selectOne(
        "select column_default from information_schema.columns where table_name = 'workspaces' and column_name = 'invite_link_default_role'"
    );

    expect($default->column_default)->toContain('member');
});
