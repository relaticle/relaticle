<?php

declare(strict_types=1);

use App\Health\ChatTurnFailureRateCheck;
use App\Health\SecretFileAccessCheck;
use App\Health\TorExitListCheck;
use App\Providers\HealthServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Facades\Health;

mutates(HealthServiceProvider::class);

it('does not register health checks when disabled', function (): void {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', false);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    expect(Health::registeredChecks())->toBeEmpty();
});

it('registers health checks when enabled', function (): void {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', true);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    $checkClasses = collect(Health::registeredChecks())
        ->map(fn ($check) => $check::class);

    expect($checkClasses)
        ->toContain(DatabaseCheck::class)
        ->toContain(RedisCheck::class)
        ->toContain(HorizonCheck::class)
        ->toContain(ChatTurnFailureRateCheck::class)
        ->toContain(TorExitListCheck::class);
});

it('registers the secret file check only when a status path is configured', function (): void {
    config()->set('app.health_checks_enabled', true);

    $registered = function (): bool {
        Health::clearChecks();
        new HealthServiceProvider(app())->boot();

        return collect(Health::registeredChecks())->contains(fn ($check): bool => $check instanceof SecretFileAccessCheck);
    };

    config()->set('health.secret_access_status_path');
    $withoutPath = $registered();

    config()->set('health.secret_access_status_path', '/var/lib/relaticle-audit/status.json');

    expect($withoutPath)->toBeFalse()
        ->and($registered())->toBeTrue();
});
