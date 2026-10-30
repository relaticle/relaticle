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
