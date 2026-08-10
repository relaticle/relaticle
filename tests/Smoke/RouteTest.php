<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * Routes that are intentionally not smoke tested.
 *
 * Third-party debug/monitoring surfaces, routes whose handler needs a signed
 * URL or an OAuth round trip, and the API (covered by tests/Feature/Api).
 *
 * @var array<int, string>
 */
const SMOKE_EXCLUDED_ROUTES = [
    '_ignition',
    '_debugbar*',
    'horizon*',
    'pulse*',
    'sanctum*',
    '__clockwork*',
    'clockwork*',
    'livewire-*',
    'auth/redirect/*',
    'auth/callback/*',
    'email/verify/*',
    'email-verification/verify/*',
    'filament/exports/*/download',
    'filament/imports/*/failed-rows/download',
    'team-invitations/*',
    'password-reset/*',
    'reset-password/*',
    'discord',
    'user/confirm-password',
    'up',
    'scheduled-deletion',
    'docs/api*',
    'api/*',
    'oauth/*',
    '.well-known/*',
];

TestResponse::macro('assertNotServerError', function (): TestResponse {
    /** @var TestResponse $this */
    Assert::assertLessThan(
        500,
        $this->getStatusCode(),
        "Response returned a server error [{$this->getStatusCode()}]."
    );

    return $this;
});

/**
 * Every GET route worth smoke testing, keyed by URI so Pest labels each case.
 *
 * Pest resolves datasets before the application is booted, so the route
 * collection is unreachable in-process. Resolve it from a short-lived Artisan
 * subprocess instead, memoised for the lifetime of this PHP process.
 *
 * Routes carrying URI parameters are skipped: this smoke pass registers no
 * model bindings, so there is no value to substitute for them.
 *
 * @return array<string, string>
 */
function smokeTestableRoutes(): array
{
    static $routes = null;

    if ($routes !== null) {
        return $routes;
    }

    $process = new Process(
        [PHP_BINARY, 'artisan', 'route:list', '--json', '--method=GET'],
        dirname(__DIR__, 2),
    );

    $process->mustRun();

    /** @var array<int, array{method: string, uri: string}> $decoded */
    $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $uris = collect($decoded)
        ->filter(fn (array $route): bool => in_array('GET', explode('|', $route['method']), true))
        ->pluck('uri')
        ->reject(fn (string $uri): bool => Str::contains($uri, '{'))
        ->reject(fn (string $uri): bool => Str::is(SMOKE_EXCLUDED_ROUTES, $uri))
        ->unique()
        ->sort()
        ->values();

    // A resolver regression that silently returned nothing would turn this file
    // into a no-op that still reports green, so fail loudly instead.
    Assert::assertGreaterThan(
        40,
        $uris->count(),
        'Smoke route resolution returned suspiciously few routes.'
    );

    return $routes = $uris->combine($uris)->all();
}

it('smoke: all GET routes return non-500 response', function (string $uri): void {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 0], 200),
    ]);

    $this->actingAs(User::factory()->withTeam()->create());

    $this->get($uri)->assertNotServerError();
})->with(smokeTestableRoutes());
