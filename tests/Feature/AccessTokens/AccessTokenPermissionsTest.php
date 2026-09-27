<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
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

test('a viewer cannot widen a pinned token past read', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($workspace);
    $this->actingAs($viewer = $viewer->fresh());

    $token = $viewer->tokens()->create([
        'name' => 'Viewer Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $workspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: [
            'workspace_id' => $owner->personalWorkspace()?->id ?? $workspace->id,
            'permissions' => ['read', 'delete'],
        ]);

    expect($token->fresh()->abilities)->toBe(['read']);
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
