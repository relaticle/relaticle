<?php

declare(strict_types=1);

use App\Features\Documentation;
use App\Features\OnboardSeed;
use App\Features\SocialAuth;
use App\Filament\Pages\CreateTeam;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

mutates(OnboardSeed::class, SocialAuth::class, Documentation::class);

describe('OnboardSeed', function (): void {
    it('seeds demo data when feature is active', function (): void {
        Feature::define(OnboardSeed::class, true);

        $user = User::factory()->create();

        $this->actingAs($user);

        livewire(CreateTeam::class)
            ->fillForm([
                'name' => 'Seed Enabled Team',
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
        $this->get('/docs')
            ->assertOk();
    });

    it('does not register documentation routes when feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_DOCUMENTATION=false');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        $this->get('/docs')
            ->assertNotFound();

        putenv('RELATICLE_FEATURE_DOCUMENTATION');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });
});
