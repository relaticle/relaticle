<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(PersonalAccessToken::class);

test('workspace_id can be set when initially null', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
    ]);

    expect($token->workspace_id)->toBeNull();

    $token->update(['workspace_id' => $workspace->id]);

    expect($token->fresh()->workspace_id)->toBe($workspace->id);
});

test('workspace_id cannot be changed once set', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create();

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $workspace->id,
    ]);

    $token->update(['workspace_id' => $otherWorkspace->id]);
})->throws(LogicException::class, 'The workspace_id attribute cannot be changed after it has been set.');

test('workspace_id cannot be set to null once set', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $workspace->id,
    ]);

    $token->update(['workspace_id' => null]);
})->throws(LogicException::class, 'The workspace_id attribute cannot be changed after it has been set.');

test('creating event allows token with valid workspace_id', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $token = $user->tokens()->create([
        'name' => 'Valid Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $workspace->id,
    ]);

    expect($token->workspace_id)->toBe($workspace->id);
});

test('creating event allows token with null workspace_id', function () {
    $user = User::factory()->withWorkspace()->create();

    $token = $user->tokens()->create([
        'name' => 'No Workspace Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
    ]);

    expect($token->workspace_id)->toBeNull();
});

test('creating event rejects token with workspace_id for another users workspace', function () {
    $user = User::factory()->withWorkspace()->create();
    $otherUser = User::factory()->withWorkspace()->create();
    $otherWorkspace = $otherUser->currentWorkspace;

    $user->tokens()->create([
        'name' => 'Stolen Workspace Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $otherWorkspace->id,
    ]);
})->throws(HttpException::class);

test('cascade deletes tokens when workspace is deleted', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $user->tokens()->create([
        'name' => 'Workspace Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $workspace->id,
    ]);

    expect($user->tokens()->count())->toBe(1);

    $workspace->forceDelete();

    expect($user->tokens()->count())->toBe(0);
});

test('other attributes can still be updated when workspace_id is set', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => ['*'],
        'workspace_id' => $workspace->id,
    ]);

    $token->update(['name' => 'Updated Token']);

    expect($token->fresh())
        ->name->toBe('Updated Token')
        ->workspace_id->toBe($workspace->id);
});
