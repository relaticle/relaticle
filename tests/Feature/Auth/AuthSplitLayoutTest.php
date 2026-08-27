<?php

declare(strict_types=1);

use App\Features\AuthSplitLayout;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use Laravel\Pennant\Feature;

mutates(AuthSplitLayout::class, Login::class, Register::class);

dataset('authentication pages', [
    'login' => '/app/login',
    'registration' => '/app/register',
]);

it('resolves its rollout state from configuration', function (): void {
    config()->set('relaticle.features.auth_split_layout', false);
    Feature::flushCache();

    expect(Feature::active(AuthSplitLayout::class))->toBeFalse();

    config()->set('relaticle.features.auth_split_layout', true);
    Feature::flushCache();

    expect(Feature::active(AuthSplitLayout::class))->toBeTrue();
});

it('renders the split layout when the controlled rollout is active', function (string $path): void {
    config()->set('relaticle.features.auth_split_layout', false);
    Feature::define(AuthSplitLayout::class, true);

    $this->get($path)
        ->assertOk()
        ->assertSee('data-auth-layout="split"', escape: false)
        ->assertSee('aria-label="Relaticle overview"', escape: false)
        ->assertDontSee('class="fi-logo"', escape: false);
})->with('authentication pages');

it('keeps the standard layout when the controlled rollout is inactive', function (string $path): void {
    config()->set('relaticle.features.auth_split_layout', true);
    Feature::define(AuthSplitLayout::class, false);

    $this->get($path)
        ->assertOk()
        ->assertDontSee('data-auth-layout="split"', escape: false)
        ->assertSee('class="fi-logo"', escape: false);
})->with('authentication pages');
