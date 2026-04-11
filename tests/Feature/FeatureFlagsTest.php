<?php

declare(strict_types=1);

use App\Features\Documentation;
use App\Features\OnboardSeed;
use App\Features\SocialAuth;
use App\Filament\Pages\CreateTeam;
use App\Models\Company;
use App\Models\User;
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
        $this->refreshApplication();

        $this->get('/auth/redirect/google')
            ->assertNotFound();

        putenv('RELATICLE_FEATURE_SOCIAL_AUTH');
    });
});

describe('Documentation', function (): void {
    it('serves documentation pages when feature is active', function (): void {
        $this->get('/docs')
            ->assertOk();
    });

    it('does not register documentation routes when feature is inactive', function (): void {
        putenv('RELATICLE_FEATURE_DOCUMENTATION=false');
        $this->refreshApplication();

        $this->get('/docs')
            ->assertNotFound();

        putenv('RELATICLE_FEATURE_DOCUMENTATION');
    });
});
