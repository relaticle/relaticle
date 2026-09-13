<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\HistoricalSchema;

function runBackfillLegacyMemberRoleMigration(): void
{
    $migration = require database_path('migrations/2026_09_01_203744_backfill_legacy_member_role_on_team_user.php');
    $migration->up();
}

function insertLegacyMembership(string $teamId, string $role): string
{
    $userId = (string) Str::ulid();

    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $userId,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $userId;
}

test('renames the legacy member role to editor without touching other roles', function (): void {
    HistoricalSchema::createTeamUserTable();

    $teamId = (string) Str::ulid();

    $legacy = insertLegacyMembership($teamId, 'member');
    $admin = insertLegacyMembership($teamId, WorkspaceRole::Admin->value);
    $viewer = insertLegacyMembership($teamId, WorkspaceRole::Viewer->value);

    runBackfillLegacyMemberRoleMigration();

    $roles = DB::table('team_user')->where('team_id', $teamId)->pluck('role', 'user_id');

    expect($roles[$legacy])->toBe(WorkspaceRole::Editor->value)
        ->and($roles[$admin])->toBe(WorkspaceRole::Admin->value)
        ->and($roles[$viewer])->toBe(WorkspaceRole::Viewer->value);
});

test('leaves a database with no legacy rows untouched', function (): void {
    HistoricalSchema::createTeamUserTable();

    $teamId = (string) Str::ulid();

    insertLegacyMembership($teamId, WorkspaceRole::Editor->value);

    runBackfillLegacyMemberRoleMigration();

    expect(DB::table('team_user')->where('team_id', $teamId)->value('role'))
        ->toBe(WorkspaceRole::Editor->value);
});
