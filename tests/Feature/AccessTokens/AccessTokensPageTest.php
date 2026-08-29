<?php

declare(strict_types=1);

use App\Filament\Pages\AccessTokens;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Jetstream\Features;

test('rest api integration link points to scribe docs', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(AccessTokens::class)
        ->assertSee(route('scribe'), escape: false)
        ->assertDontSee('href=""', escape: false);
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');
