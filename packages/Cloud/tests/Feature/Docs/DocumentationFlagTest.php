<?php

declare(strict_types=1);

use App\Features\Documentation;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Http;

mutates(Documentation::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 0], 200),
    ]);
});

it('serves documentation pages when feature is active', function (): void {
    $this->get('/developers')
        ->assertOk();
});

it('does not register documentation routes when feature is inactive', function (): void {
    putenv('RELATICLE_FEATURE_DOCUMENTATION=false');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
    RouteServiceProvider::loadCachedRoutesUsing(null);
    LoadConfiguration::alwaysUse(null);
    $this->refreshApplication();

    $this->get('/developers')
        ->assertNotFound();

    $this->get('/docs')
        ->assertNotFound();

    putenv('RELATICLE_FEATURE_DOCUMENTATION');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
});

it('still serves every marketing page when documentation is inactive', function (): void {
    putenv('RELATICLE_FEATURE_DOCUMENTATION=false');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
    RouteServiceProvider::loadCachedRoutesUsing(null);
    LoadConfiguration::alwaysUse(null);
    $this->refreshApplication();

    // refreshApplication() rebuilds the container, discarding the
    // withoutVite() from TestCase::setUp(). These pages render the full
    // marketing layout, so without this they need a built manifest, which
    // CI does not have when it runs the suite.
    $this->withoutVite();

    // The shared footer and both product pages link routes the Documentation
    // provider registers. Unguarded, a single route() call 500s the whole
    // marketing site the moment this flag is turned off.
    $this->get('/')->assertOk();
    $this->get('/pricing')->assertOk();
    $this->get('/ai')->assertOk();
    $this->get('/self-hosted')->assertOk();
    $this->get('/crm-for-claude')->assertOk();

    putenv('RELATICLE_FEATURE_DOCUMENTATION');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
});
