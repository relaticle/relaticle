<?php

declare(strict_types=1);

use App\Filament\Clusters\Settings;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\NotificationPreferences;
use App\Models\User;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\MacroServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Route;
use Relaticle\Chat\ChatServiceProvider;

mutates(MacroServiceProvider::class, AppPanelProvider::class, ChatServiceProvider::class);

describe('app panel configuration - path mode (default)', function () {
    it('registers panel with path prefix and no domain constraint', function () {
        $panel = Filament::getPanel('app');

        expect($panel->getDomains())->toBeEmpty()
            ->and($panel->getPath())->toBe(config('app.app_panel_path', 'app'));
    });

    it('serves login page at the panel path', function () {
        $panelPath = config('app.app_panel_path', 'app');

        $this->get("/{$panelPath}/login")->assertOk();
    });

    it('registers each Settings cluster page once so sub-nav tabs are not duplicated', function () {
        $components = Filament::getPanel('app')->getClusteredComponents(Settings::class);

        expect($components)
            ->toContain(EditProfile::class, NotificationPreferences::class)
            ->and(array_count_values($components))->each->toBe(1);
    });
});

describe('getAppUrl macro - path mode', function () {
    beforeEach(function () {
        config([
            'app.app_panel_domain' => null,
            'app.app_panel_path' => 'app',
            'app.url' => 'https://example.com',
        ]);
    });

    it('returns path-based URL with default path', function () {
        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login')
            ->and(url()->getAppUrl())->toBe('https://example.com/app');
    });

    it('handles custom panel path', function () {
        config(['app.app_panel_path' => 'crm']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/crm/login');
    });

    it('handles port in APP_URL for Docker deployments', function () {
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getAppUrl('login'))->toBe('http://localhost:8080/app/login');
    });

    it('handles IP address with port', function () {
        config(['app.url' => 'http://192.168.1.100:8080']);

        expect(url()->getAppUrl('dashboard'))->toBe('http://192.168.1.100:8080/app/dashboard');
    });

    it('handles nested path segments', function () {
        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://example.com/app/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        expect(url()->getAppUrl('/login'))->toBe('https://example.com/app/login');
    });

    it('handles http scheme', function () {
        config(['app.url' => 'http://example.com']);

        expect(url()->getAppUrl('login'))->toBe('http://example.com/app/login');
    });

    it('strips trailing slash from APP_URL', function () {
        config(['app.url' => 'https://example.com/']);

        expect(url()->getAppUrl('login'))->toBe('https://example.com/app/login');
    });
});

describe('getAppUrl macro - domain mode', function () {
    beforeEach(function () {
        config([
            'app.app_panel_domain' => 'app.example.com',
            'app.url' => 'https://example.com',
        ]);
    });

    it('returns domain-based URL when APP_PANEL_DOMAIN is set', function () {
        expect(url()->getAppUrl('login'))->toBe('https://app.example.com/login')
            ->and(url()->getAppUrl())->toBe('https://app.example.com');
    });

    it('uses scheme from APP_URL', function () {
        config([
            'app.app_panel_domain' => 'crm.example.com',
            'app.url' => 'http://example.com',
        ]);

        expect(url()->getAppUrl('login'))->toBe('http://crm.example.com/login');
    });

    it('handles nested path segments', function () {
        expect(url()->getAppUrl('teams/1/companies'))->toBe('https://app.example.com/teams/1/companies');
    });

    it('handles path with leading slash', function () {
        expect(url()->getAppUrl('/login'))->toBe('https://app.example.com/login');
    });

    it('preserves port from APP_URL', function () {
        config([
            'app.app_panel_domain' => 'app.localhost',
            'app.url' => 'http://localhost:8080',
        ]);

        expect(url()->getAppUrl('login'))->toBe('http://app.localhost:8080/login')
            ->and(url()->getAppUrl())->toBe('http://app.localhost:8080');
    });
});

describe('getPublicUrl macro', function () {
    it('returns APP_URL based URL', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });

    it('returns clean base URL when path is empty', function () {
        config(['app.url' => 'https://example.com']);

        expect(url()->getPublicUrl())->toBe('https://example.com');
    });

    it('preserves port in URL', function () {
        config(['app.url' => 'http://localhost:8080']);

        expect(url()->getPublicUrl('about'))->toBe('http://localhost:8080/about');
    });

    it('is not affected by app panel domain config', function () {
        config([
            'app.app_panel_domain' => 'app.example.com',
            'app.url' => 'https://example.com',
        ]);

        expect(url()->getPublicUrl('about'))->toBe('https://example.com/about');
    });
});

/**
 * Chat cites every record as a `/r/{type}/{id}` chip. Production subdomain-routes
 * the app panel (APP_PANEL_DOMAIN), which strips the "/app" prefix off the tenant
 * routes and leaves `{tenant:slug}/{resource}/{record}` matching `/r/people/{id}`
 * as tenant "r" plus the People resource. Panel routes carry the domain and
 * Laravel matches every domain route ahead of every domainless one, so the chip
 * resolved locally and 404'd in production until ChatServiceProvider started
 * registering its routes on the panel domain during the register phase.
 */
$recordPermalinkTypes = ['company', 'people', 'opportunity', 'task', 'note', 'custom_field'];

describe('chat record permalinks - path mode (default)', function () use ($recordPermalinkTypes) {
    it('routes each permalink to the chat redirect', function (string $type) {
        $this->get("/r/{$type}/01ABC");

        expect(Route::currentRouteName())->toBe('chat.record-redirect');
    })->with($recordPermalinkTypes);
});

describe('chat record permalinks - domain mode', function () use ($recordPermalinkTypes) {
    beforeEach(function () {
        putenv('APP_PANEL_DOMAIN=app.example.com');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();
    });

    afterEach(function () {
        putenv('APP_PANEL_DOMAIN');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });

    it('routes each permalink to the chat redirect, not to a panel resource', function (string $type) {
        $this->get("http://app.example.com/r/{$type}/01ABC");

        expect(Route::currentRouteName())->toBe('chat.record-redirect');
    })->with($recordPermalinkTypes);

    it('leaves panel resource URLs to the panel', function () {
        $this->get('http://app.example.com/acme/people/01ABC');

        expect(Route::currentRouteName())->toBe('filament.app.resources.people.view');
    });
});

it('gives every chat route throttle its own bucket so limiters cannot starve each other', function (): void {
    $throttles = collect(app('router')->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'chat.'))
        ->flatMap(fn ($route) => collect($route->gatherMiddleware())
            ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'))
            ->map(fn (string $m): array => ['route' => (string) $route->getName(), 'middleware' => $m]))
        ->values();

    // Positive control first. Without it this test passes vacuously: rename the
    // route prefix, move the throttles into a group, or change how gatherMiddleware
    // stringifies them, and the collection is empty and the assertion below is green
    // while the protection it guards is gone.
    expect($throttles->count())->toBeGreaterThanOrEqual(10);

    // An inline throttle must carry a key prefix (the third argument). The default
    // signature is just the user id, so two unprefixed limiters on different routes
    // share one bucket and starve each other.
    $unprefixed = $throttles
        ->filter(fn (array $t): bool => preg_match('/^throttle:\d+,\d+(,\s*)?$/', $t['middleware']) === 1)
        ->map(fn (array $t): string => $t['route'].' '.$t['middleware'])
        ->values();

    expect($unprefixed->all())->toBe([]);
});

it('does not let one chat route consume another route\'s rate limit allowance', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    // Exhaust the mentions bucket (60/min).
    foreach (range(1, 61) as $ignored) {
        $response = $this->get(route('chat.mentions', ['q' => 'a']));
    }

    expect($response->status())->toBe(429);

    // A different route with its own bucket must be unaffected. This is the exact
    // regression the routes file documents: unprefixed limiters shared one bucket,
    // so mention autocompletes consumed the transcribe allowance.
    $conversations = $this->get(route('chat.conversations'));

    expect($conversations->status())->not->toBe(429);
});
