<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\HistoricalSchema;

function runRestoreWorkspaceUserUniqueConstraintMigration(): void
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

function insertMembership(string $teamId, string $userId, string $role): void
{
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $userId,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('removes duplicate memberships keeping the earliest row and restores the unique constraint', function (): void {
    HistoricalSchema::createTeamUserTable();

    $teamId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    foreach (['admin', 'editor', 'editor', 'editor'] as $role) {
        insertMembership($teamId, $userId, $role);
    }

    expect(DB::table('team_user')->where('team_id', $teamId)->count())->toBe(4);

    runRestoreWorkspaceUserUniqueConstraintMigration();

    $memberships = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $userId)->get();

    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->role)->toBe('admin')
        ->and(teamUserUniqueConstraintExists())->toBeTrue();

    $violation = false;

    try {
        DB::transaction(fn () => insertMembership($teamId, $userId, 'editor'));
    } catch (UniqueConstraintViolationException) {
        $violation = true;
    }

    expect($violation)->toBeTrue();
});

test('is a no-op on a healthy database', function (): void {
    HistoricalSchema::createTeamUserTable(withUniqueConstraint: true);

    $teamId = (string) Str::ulid();

    insertMembership($teamId, (string) Str::ulid(), 'editor');

    runRestoreWorkspaceUserUniqueConstraintMigration();

    expect(DB::table('team_user')->where('team_id', $teamId)->count())->toBe(1)
        ->and(teamUserUniqueConstraintExists())->toBeTrue();
});
