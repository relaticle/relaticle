<?php

declare(strict_types=1);

use App\Filament\Pages\AccessTokens;
use App\Models\User;
use Laravel\Jetstream\Features;

test('rest api integration link points to scribe docs', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());

    livewire(AccessTokens::class)
        ->assertSee(route('scribe'), escape: false)
        ->assertDontSee('href=""', escape: false);
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');
