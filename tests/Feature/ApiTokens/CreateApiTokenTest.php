<?php

declare(strict_types=1);

use App\Livewire\App\ApiTokens\CreateApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

test('api tokens can be created with team and expiration', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'team_id' => $user->currentTeam->id,
            'expiration' => '30',
            'permissions' => ['read', 'update'],
        ])
        ->call('createToken')
        ->assertHasNoFormErrors();

    $token = $user->fresh()->tokens->first();

    expect($token)
        ->name->toEqual('Test Token')
        ->team_id->toEqual($user->currentTeam->id)
        ->can('read')->toBeTrue()
        ->can('delete')->toBeFalse();

    expect($token->expires_at)->not->toBeNull();
    expect($token->expires_at->startOfDay()->equalTo(now()->addDays(30)->startOfDay()))->toBeTrue();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token with no expiration stores null expires_at', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Forever Token',
            'team_id' => $user->currentTeam->id,
            'expiration' => '0',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasNoFormErrors();

    expect($user->fresh()->tokens->first()->expires_at)->toBeNull();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('cannot create token for a team user does not belong to', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());
    $otherTeam = Team::factory()->create();

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Sneaky Token',
            'team_id' => $otherTeam->id,
            'expiration' => '30',
            'permissions' => ['read'],
        ])
        ->call('createToken');

    expect($user->fresh()->tokens)->toHaveCount(0);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token name is required', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => '',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['name' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token name must be unique per user', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->tokens()->create([
        'name' => 'Existing Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
    ]);

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Existing Token',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['name' => 'unique']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('permissions are required', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'permissions' => [],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['permissions' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('plain text token is shown after creation', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $component = livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'team_id' => $user->currentTeam->id,
            'expiration' => '7',
            'permissions' => ['read'],
        ])
        ->call('createToken');

    expect($component->get('plainTextToken'))->not->toBeNull();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('team_id and expiration are required', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    livewire(CreateApiToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'team_id' => null,
            'expiration' => null,
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['team_id' => 'required', 'expiration' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');
