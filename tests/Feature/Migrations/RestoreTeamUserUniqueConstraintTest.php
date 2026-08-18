<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function runRestoreTeamUserUniqueConstraintMigration(): void
{
    $migration = require database_path('migrations/2026_08_18_111736_restore_team_user_unique_constraint.php');
    $migration->up();
}

function teamUserUniqueConstraintExists(): bool
{
    return DB::selectOne(
        "SELECT 1 FROM pg_constraint WHERE conname = 'team_user_team_id_user_id_unique' AND conrelid = 'team_user'::regclass"
    ) !== null;
}

test('removes duplicate memberships keeping the earliest row and restores the unique constraint', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();

    DB::statement('ALTER TABLE team_user DROP CONSTRAINT team_user_team_id_user_id_unique');

    foreach (['admin', 'editor', 'editor', 'editor'] as $role) {
        DB::table('team_user')->insert([
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect($team->users()->count())->toBe(4);

    runRestoreTeamUserUniqueConstraintMigration();

    $memberships = DB::table('team_user')->where('team_id', $team->id)->where('user_id', $member->id)->get();

    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->role)->toBe('admin')
        ->and(teamUserUniqueConstraintExists())->toBeTrue();

    $violation = false;

    try {
        DB::transaction(fn () => DB::table('team_user')->insert([
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'editor',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    } catch (UniqueConstraintViolationException) {
        $violation = true;
    }

    expect($violation)->toBeTrue();
});

test('is a no-op on a healthy database', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();

    $team->users()->attach($member, ['role' => 'editor']);

    runRestoreTeamUserUniqueConstraintMigration();

    expect($team->users()->count())->toBe(1)
        ->and(teamUserUniqueConstraintExists())->toBeTrue();
});
