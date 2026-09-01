<?php

declare(strict_types=1);

use App\Casts\AsCanonicalEmail;
use App\Models\Team;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

mutates(EmailAddress::class, AsCanonicalEmail::class);

function runEmailNormalizationMigration(): void
{
    $migration = require base_path('database/migrations/2026_08_30_181547_normalize_emails_to_lowercase.php');

    $migration->up();
}

function insertRawUser(string $email): string
{
    $id = (string) Str::ulid();

    DB::table('users')->insert([
        'id' => $id,
        'name' => 'Raw',
        'email' => $email,
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insertRawInvitation(string $teamId, string $email): string
{
    $id = (string) Str::ulid();

    DB::table('team_invitations')->insert([
        'id' => $id,
        'team_id' => $teamId,
        'email' => $email,
        'role' => 'member',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('lowercases a mixed-case user email', function (): void {
    $id = insertRawUser('Legacy-Mixed@Example.com');

    runEmailNormalizationMigration();

    expect(DB::table('users')->where('id', $id)->value('email'))->toBe('legacy-mixed@example.com');
});

it('leaves colliding user rows untouched rather than merging accounts', function (): void {
    $lower = insertRawUser('collide@example.com');
    $upper = insertRawUser('Collide@Example.com');

    runEmailNormalizationMigration();

    expect(DB::table('users')->where('id', $lower)->value('email'))->toBe('collide@example.com')
        ->and(DB::table('users')->where('id', $upper)->value('email'))->toBe('Collide@Example.com');
});

it('lowercases a mixed-case invitation email', function (): void {
    $team = Team::factory()->create();
    $id = insertRawInvitation($team->id, 'Invited@Example.com');

    runEmailNormalizationMigration();

    expect(DB::table('team_invitations')->where('id', $id)->value('email'))->toBe('invited@example.com');
});

it('scopes the invitation collision check to one team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $sameTeamLower = insertRawInvitation($teamA->id, 'dup@example.com');
    $sameTeamUpper = insertRawInvitation($teamA->id, 'Dup@Example.com');
    $otherTeam = insertRawInvitation($teamB->id, 'Dup@Example.com');

    runEmailNormalizationMigration();

    expect(DB::table('team_invitations')->where('id', $sameTeamLower)->value('email'))->toBe('dup@example.com')
        ->and(DB::table('team_invitations')->where('id', $sameTeamUpper)->value('email'))->toBe('Dup@Example.com')
        ->and(DB::table('team_invitations')->where('id', $otherTeam)->value('email'))->toBe('dup@example.com');
});

it('canonicalizes an email written through the model cast', function (): void {
    $user = User::factory()->create(['email' => '  Mixed.Case@Example.COM  ']);

    expect(DB::table('users')->where('id', $user->id)->value('email'))->toBe('mixed.case@example.com');
});
