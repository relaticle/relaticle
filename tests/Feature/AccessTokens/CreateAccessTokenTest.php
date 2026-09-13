<?php

declare(strict_types=1);

use App\Livewire\App\AccessTokens\CreateAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

mutates(User::class);

test('the expiration field defaults to 180 days', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->assertFormSet(['expiration' => '180'])
        ->fillForm([
            'name' => 'Default Expiry Token',
            'workspace_id' => $user->currentWorkspace->id,
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasNoFormErrors();

    $token = $user->fresh()->tokens->first();

    expect($token->expires_at->startOfDay()->equalTo(now()->addDays(180)->startOfDay()))->toBeTrue();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('api tokens can be created with workspace and expiration', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'workspace_id' => $user->currentWorkspace->id,
            'expiration' => '30',
            'permissions' => ['read', 'update'],
        ])
        ->call('createToken')
        ->assertHasNoFormErrors();

    $token = $user->fresh()->tokens->first();

    expect($token)
        ->name->toEqual('Test Token')
        ->workspace_id->toEqual($user->currentWorkspace->id)
        ->can('read')->toBeTrue()
        ->can('delete')->toBeFalse();

    expect($token->expires_at)->not->toBeNull();
    expect($token->expires_at->startOfDay()->equalTo(now()->addDays(30)->startOfDay()))->toBeTrue();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token with no expiration stores null expires_at', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Forever Token',
            'workspace_id' => $user->currentWorkspace->id,
            'expiration' => '0',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasNoFormErrors();

    expect($user->fresh()->tokens->first()->expires_at)->toBeNull();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('cannot create token for a workspace user does not belong to', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $otherWorkspace = Workspace::factory()->create();

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Sneaky Token',
            'workspace_id' => $otherWorkspace->id,
            'expiration' => '30',
            'permissions' => ['read'],
        ])
        ->call('createToken');

    expect($user->fresh()->tokens)->toBeEmpty();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token name is required', function () {
    $this->actingAs(User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => '',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['name' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('token name must be unique per user', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Existing Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
    ]);

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Existing Token',
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['name' => 'unique']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('permissions are required', function () {
    $this->actingAs(User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'permissions' => [],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['permissions' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('plain text token is shown after creation', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $component = livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'workspace_id' => $user->currentWorkspace->id,
            'expiration' => '7',
            'permissions' => ['read'],
        ])
        ->call('createToken');

    expect($component->get('plainTextToken'))->not->toBeNull();
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('workspace_id and expiration are required', function () {
    $this->actingAs(User::factory()->withWorkspace()->create());

    livewire(CreateAccessToken::class)
        ->fillForm([
            'name' => 'Test Token',
            'workspace_id' => null,
            'expiration' => null,
            'permissions' => ['read'],
        ])
        ->call('createToken')
        ->assertHasFormErrors(['workspace_id' => 'required', 'expiration' => 'required']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');
