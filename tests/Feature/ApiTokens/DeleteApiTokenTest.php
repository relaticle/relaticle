<?php

declare(strict_types=1);

use App\Livewire\App\ApiTokens\ManageApiTokens;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

test('api tokens can be deleted', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['create', 'read'],
    ]);

    livewire(ManageApiTokens::class)
        ->callTableAction('delete', $token);

    expect($user->fresh()->tokens)->toHaveCount(0);
})->skip(function () {
    return ! Features::hasApiFeatures();
}, 'API support is not enabled.');
