<?php

declare(strict_types=1);

use App\Http\Middleware\RequireIdentityConfirmation;
use App\Http\Middleware\RequireOperationGrant;
use App\Providers\FortifyServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

mutates(RequireOperationGrant::class);

/**
 * The gate is attached by iterating the route collection in a booted() callback
 * and matching vendor route names. A vendor rename, or a provider registering
 * later, detaches it silently: no boot error, no failing behavioural test.
 */
test('every sensitive vendor write route still carries its operation grant', function (string $routeName, string $operation): void {
    $route = collect(resolve(Router::class)->getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === $routeName);

    expect($route)->not->toBeNull("Route {$routeName} no longer exists; the grant gate is now attached to nothing.");
    expect($route->gatherMiddleware())->toContain("require-operation:{$operation}");
})->with([
    ['passkey.store', 'add_passkey'],
    ['passkey.destroy', 'delete_passkey'],
    ['two-factor.enable', 'manage_mfa,enable'],
    ['two-factor.disable', 'manage_mfa,disable'],
    ['two-factor.confirm', 'manage_mfa,confirm'],
    ['two-factor.qr-code', 'manage_mfa,show_qr_code'],
    ['two-factor.secret-key', 'manage_mfa,show_secret_key'],
    ['two-factor.recovery-codes', 'manage_mfa,show_recovery_codes'],
    ['two-factor.regenerate-recovery-codes', 'manage_mfa,regenerate_recovery_codes'],
]);

test('the password.confirm alias still resolves to the application middleware', function (): void {
    $aliases = resolve(Router::class)->getMiddleware();

    expect($aliases)->toHaveKey('password.confirm')
        ->and($aliases['password.confirm'])->toBe(RequireIdentityConfirmation::class)
        ->and($aliases)->toHaveKey('require-operation')
        ->and($aliases['require-operation'])->toBe(RequireOperationGrant::class);
});

test('attaching operation grants raises no deprecation for unnamed routes', function (): void {
    Route::get('/unnamed-probe', fn (): string => 'ok');
    $deprecations = [];
    set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_DEPRECATED);

    try {
        $this->app->getProvider(FortifyServiceProvider::class)->boot();
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBe([]);
});
