<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function runBackfillLegacyMemberRoleMigration(): void
{
    $migration = require database_path('migrations/2026_09_01_203744_backfill_legacy_member_role_on_team_user.php');
    $migration->up();
}

test('renames the legacy member role to editor without touching other roles', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $legacy = User::factory()->create();
    $admin = User::factory()->create();
    $viewer = User::factory()->create();

    $team->users()->attach($legacy, ['role' => 'editor']);
    DB::table('team_user')->where('user_id', $legacy->id)->update(['role' => 'member']);
    $team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->users()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    runBackfillLegacyMemberRoleMigration();

    $roles = DB::table('team_user')->where('team_id', $team->id)->pluck('role', 'user_id');

    expect($roles[$legacy->id])->toBe(TeamRole::Editor->value)
        ->and($roles[$admin->id])->toBe(TeamRole::Admin->value)
        ->and($roles[$viewer->id])->toBe(TeamRole::Viewer->value);
});

test('leaves a database with no legacy rows untouched', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();

    $team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    runBackfillLegacyMemberRoleMigration();

    expect(DB::table('team_user')->where('team_id', $team->id)->value('role'))
        ->toBe(TeamRole::Editor->value);
});
