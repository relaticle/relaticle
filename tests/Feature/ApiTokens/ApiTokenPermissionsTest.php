<?php

declare(strict_types=1);

use App\Livewire\App\ApiTokens\ManageApiTokens;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

test('api token permissions can be updated', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['create', 'read'],
    ]);

    livewire(ManageApiTokens::class)
        ->callTableAction('permissions', $token, data: [
            'permissions' => ['delete', 'update'],
        ]);

    $freshToken = $user->fresh()->tokens->first();

    expect($freshToken->abilities)->toBe(['delete', 'update']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows team name column', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'team_id' => $user->currentTeam->id,
    ]);

    livewire(ManageApiTokens::class)
        ->assertCanRenderTableColumn('team.name');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows expiration column', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->tokens()->create([
        'name' => 'Expiring Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'expires_at' => now()->addDays(30),
    ]);

    livewire(ManageApiTokens::class)
        ->assertCanRenderTableColumn('expires_at');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');
