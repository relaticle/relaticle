<?php

declare(strict_types=1);

use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Features\AccountDeletion;
use App\Features\Billing;
use App\Features\Documentation;
use App\Features\OnboardSeed;
use App\Features\SocialAuth;
use App\Filament\Pages\EditProfile;
use App\Livewire\App\Profile\DeleteAccount;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Laravel\Pennant\Feature;

mutates(AccountDeletion::class, OnboardSeed::class, SaveOnboardingUseCase::class, SocialAuth::class, Documentation::class, Billing::class, EditProfile::class);

describe('AccountDeletion', function (): void {
    it('hides account deletion from Profile by default', function (): void {
        $this->actingAs($user = User::factory()->withWorkspace()->create());
        Filament::setTenant($user->currentWorkspace);

        livewire(EditProfile::class)->assertDontSeeLivewire(DeleteAccount::class);

        expect(Feature::active(AccountDeletion::class))->toBeFalse();
    });

    it('shows account deletion when enabled through config', function (): void {
        config()->set('relaticle.features.account_deletion', true);
        $this->actingAs($user = User::factory()->withWorkspace()->create());
        Filament::setTenant($user->currentWorkspace);

        livewire(EditProfile::class)->assertSeeLivewire(DeleteAccount::class);
    });
});

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

        onboardWorkspace($user, ['name' => 'Seed Enabled Workspace']);

        $workspace = $user->fresh()->personalWorkspace();

        expect(Company::where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0);
    });

    it('skips demo data when feature is inactive', function (): void {
        Feature::define(OnboardSeed::class, false);

        $user = User::factory()->create();

        onboardWorkspace($user, ['name' => 'Seed Disabled Workspace']);

        $workspace = $user->fresh()->personalWorkspace();

        expect(Company::where('workspace_id', $workspace->id)->count())->toBe(0);
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
