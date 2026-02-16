<?php

declare(strict_types=1);

use App\Providers\HealthServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Facades\Health;

mutates(HealthServiceProvider::class);

it('does not register health checks when disabled', function () {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', false);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    expect(Health::registeredChecks())->toBeEmpty();
});

it('registers health checks when enabled', function () {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', true);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    $checkClasses = collect(Health::registeredChecks())
        ->map(fn ($check) => $check::class);

    expect($checkClasses)
        ->toContain(DatabaseCheck::class)
        ->toContain(RedisCheck::class)
        ->toContain(HorizonCheck::class);
});
