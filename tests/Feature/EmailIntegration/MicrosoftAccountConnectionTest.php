<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Laravel\Socialite\Facades\Socialite;

mutates(AppServiceProvider::class);

it('resolves the azure socialite driver', function (): void {
    expect(fn () => Socialite::driver('azure'))->not->toThrow(Throwable::class);
});
