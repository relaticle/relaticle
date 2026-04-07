<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(PersonalAccessToken::class);

test('team_id can be set when initially null', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
    ]);

    expect($token->team_id)->toBeNull();

    $token->update(['team_id' => $team->id]);

    expect($token->fresh()->team_id)->toBe($team->id);
});

test('team_id cannot be changed once set', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $otherTeam = Team::factory()->create();

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    $token->update(['team_id' => $otherTeam->id]);
})->throws(LogicException::class, 'The team_id attribute cannot be changed after it has been set.');

test('team_id cannot be set to null once set', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    $token->update(['team_id' => null]);
})->throws(LogicException::class, 'The team_id attribute cannot be changed after it has been set.');

test('creating event allows token with valid team_id', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $token = $user->tokens()->create([
        'name' => 'Valid Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    expect($token->team_id)->toBe($team->id);
});

test('creating event allows token with null team_id', function () {
    $user = User::factory()->withTeam()->create();

    $token = $user->tokens()->create([
        'name' => 'No Team Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
    ]);

    expect($token->team_id)->toBeNull();
});

test('creating event rejects token with team_id for another users team', function () {
    $user = User::factory()->withTeam()->create();
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $user->tokens()->create([
        'name' => 'Stolen Team Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $otherTeam->id,
    ]);
})->throws(HttpException::class);

test('cascade deletes tokens when team is deleted', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $user->tokens()->create([
        'name' => 'Team Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    expect($user->tokens()->count())->toBe(1);

    $team->forceDelete();

    expect($user->tokens()->count())->toBe(0);
});

test('other attributes can still be updated when team_id is set', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    $token->update(['name' => 'Updated Token']);

    expect($token->fresh())
        ->name->toBe('Updated Token')
        ->team_id->toBe($team->id);
});
