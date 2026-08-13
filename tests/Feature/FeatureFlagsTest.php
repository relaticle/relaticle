<?php

declare(strict_types=1);

use App\Features\Billing;
use App\Features\Blog;
use App\Features\Documentation;
use App\Features\Marketing;
use App\Features\OnboardSeed;
use App\Features\SocialAuth;
use App\Filament\Pages\CreateTeam;
use App\Models\Company;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;

mutates(OnboardSeed::class, SocialAuth::class, Documentation::class, Billing::class, Blog::class, Marketing::class);

describe('Billing', function (): void {
    it('is off by default', function (): void {
        expect(Feature::active(Billing::class))->toBeFalse();
    });

    it('activates via config', function (): void {
        config()->set('relaticle.features.billing', true);
        Feature::flushCache();

        expect(Feature::active(Billing::class))->toBeTrue();
    });
});

describe('OnboardSeed', function (): void {
    it('seeds demo data when feature is active', function (): void {
        Feature::define(OnboardSeed::class, true);

        $user = User::factory()->create();

        $this->actingAs($user);

        livewire(CreateTeam::class)
            ->fillForm([
                'name' => 'Seed Enabled Team',
                'onboarding_use_case' => 'other',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $team = $user->fresh()->personalTeam();

        expect(Company::where('team_id', $team->id)->count())->toBeGreaterThan(0);
    });

    it('skips demo data when feature is inactive', function (): void {
        Feature::define(OnboardSeed::class, false);

        $user = User::factory()->create();

        $this->actingAs($user);

        livewire(CreateTeam::class)
            ->fillForm([
                'name' => 'Seed Disabled Team',
                'onboarding_use_case' => 'other',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $team = $user->fresh()->personalTeam();

        expect(Company::where('team_id', $team->id)->count())->toBe(0);
    });
});

describe('SocialAuth', function (): void {
    it('registers social auth routes when feature is active', function (): void {
        $this->get(route('auth.socialite.redirect', 'google'))
            ->assertRedirect();
    });

    it('does not register social auth routes when feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_SOCIAL_AUTH=false');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        $this->get('/auth/redirect/google')
            ->assertNotFound();

        putenv('RELATICLE_FEATURE_SOCIAL_AUTH');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });
});

describe('Documentation', function (): void {
    beforeEach(function () {
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
});

describe('Blog', function (): void {
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
        // The flag gates the PUBLIC blog; staff keep the authoring surface either way,
        // so posts can be written before launch.
        expect(Route::has('filament.sysadmin.resources.posts.index'))->toBeTrue()
            ->and(Route::has('filament.app.resources.posts.index'))->toBeFalse()
            ->and(Route::has('filament.app.resources.categories.index'))->toBeFalse()
            ->and(Route::has('filament.app.resources.tags.index'))->toBeFalse();
    });
});

describe('Marketing', function (): void {
    beforeEach(function () {
        Http::fake([
            'api.github.com/*' => Http::response(['stargazers_count' => 0], 200),
        ]);
    });

    it('is on by default', function (): void {
        expect(Feature::active(Marketing::class))->toBeTrue();
    });

    it('serves marketing routes when the feature is active', function (): void {
        $this->get('/')->assertOk();
        $this->get('/pricing')->assertOk();
    });

    it('redirects marketing routes to the app login when the feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_MARKETING=false');
        $_ENV['RELATICLE_FEATURE_MARKETING'] = $_SERVER['RELATICLE_FEATURE_MARKETING'] = 'false';
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        $this->get('/')->assertRedirect(url()->getAppUrl('login'));
        $this->get('/pricing')->assertRedirect(url()->getAppUrl('login'));
        $this->get('/contact')->assertRedirect(url()->getAppUrl('login'));
        $this->get('/terms-of-service')->assertRedirect(url()->getAppUrl('login'));
        $this->get('/privacy-policy')->assertRedirect(url()->getAppUrl('login'));

        putenv('RELATICLE_FEATURE_MARKETING');
        unset($_ENV['RELATICLE_FEATURE_MARKETING'], $_SERVER['RELATICLE_FEATURE_MARKETING']);
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });

    it('keeps /developers and /help reachable when the feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_MARKETING=false');
        $_ENV['RELATICLE_FEATURE_MARKETING'] = $_SERVER['RELATICLE_FEATURE_MARKETING'] = 'false';
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        $this->get('/developers')->assertOk();
        $this->get('/help')->assertOk();

        putenv('RELATICLE_FEATURE_MARKETING');
        unset($_ENV['RELATICLE_FEATURE_MARKETING'], $_SERVER['RELATICLE_FEATURE_MARKETING']);
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });

    it('renders a guest-layout page outside the marketing group when the feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_MARKETING=false');
        $_ENV['RELATICLE_FEATURE_MARKETING'] = $_SERVER['RELATICLE_FEATURE_MARKETING'] = 'false';
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create();
        $invitation = TeamInvitation::factory()->expired()->create([
            'team_id' => $team->id,
            'email' => $user->email,
        ]);
        $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->actingAs($user)
            ->get($acceptUrl)
            ->assertOk()
            ->assertViewIs('teams.invitation-expired');

        putenv('RELATICLE_FEATURE_MARKETING');
        unset($_ENV['RELATICLE_FEATURE_MARKETING'], $_SERVER['RELATICLE_FEATURE_MARKETING']);
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });
});
