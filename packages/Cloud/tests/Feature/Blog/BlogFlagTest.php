<?php

declare(strict_types=1);

use App\Features\Blog;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Route;

mutates(Blog::class);

it('serves the blog when the feature is active', function (): void {
    $this->get(route('blog.index'))
        ->assertOk();
});

it('does not register blog routes when the feature is inactive', function (): void {
    putenv('RELATICLE_FEATURE_BLOG=false');
    unset($_ENV['RELATICLE_FEATURE_BLOG'], $_SERVER['RELATICLE_FEATURE_BLOG']);
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
    RouteServiceProvider::loadCachedRoutesUsing(null);
    LoadConfiguration::alwaysUse(null);
    $this->refreshApplication();

    $this->get('/blog')
        ->assertNotFound();

    $this->get('/blog/feed')
        ->assertNotFound();

    putenv('RELATICLE_FEATURE_BLOG=true');
    $_ENV['RELATICLE_FEATURE_BLOG'] = $_SERVER['RELATICLE_FEATURE_BLOG'] = 'true';
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
});

it('keeps the blog admin in the sysadmin panel and out of the customer panel', function (): void {
    expect(Route::has('filament.sysadmin.resources.posts.index'))->toBeTrue()
        ->and(Route::has('filament.app.resources.posts.index'))->toBeFalse()
        ->and(Route::has('filament.app.resources.categories.index'))->toBeFalse()
        ->and(Route::has('filament.app.resources.tags.index'))->toBeFalse();
});
