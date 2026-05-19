<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

it('enables the passkeys feature in Fortify', function (): void {
    expect(Features::enabled(Features::passkeys()))->toBeTrue();
});

it('configures relying party id from APP_URL host', function (): void {
    expect(config('fortify.passkeys.relying_party_id'))->not->toBeEmpty();
});

it('registers passkey routes', function (): void {
    expect(app('router')->has('passkey.login'))->toBeTrue()
        ->and(app('router')->has('passkey.registration-options'))->toBeTrue()
        ->and(app('router')->has('passkey.store'))->toBeTrue()
        ->and(app('router')->has('passkey.destroy'))->toBeTrue();
});
