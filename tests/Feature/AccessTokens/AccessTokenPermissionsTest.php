<?php

declare(strict_types=1);

use App\Livewire\App\AccessTokens\ManageAccessTokens;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

mutates(User::class);

test('api token permissions can be updated', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['create', 'read'],
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: [
            'permissions' => ['delete', 'update'],
        ]);

    $freshToken = $user->fresh()->tokens->first();

    expect($freshToken->abilities)->toBe(['delete', 'update']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows workspace name column', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->assertCanRenderTableColumn('workspace.name');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows expiration column', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Expiring Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'expires_at' => now()->addDays(30),
    ]);

    livewire(ManageAccessTokens::class)
        ->assertCanRenderTableColumn('expires_at');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');
