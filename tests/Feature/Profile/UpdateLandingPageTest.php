<?php

declare(strict_types=1);

use App\Actions\User\UpdateLandingPage;
use App\Livewire\App\Profile\UpdateLandingPage as UpdateLandingPageComponent;
use App\Models\User;

mutates(UpdateLandingPage::class, UpdateLandingPageComponent::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create([
        'email' => 'landing@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($this->user);
});

describe('landing page preference', function (): void {
    test('component renders with the current preference selected', function (): void {
        Livewire::test(UpdateLandingPageComponent::class)
            ->assertSuccessful()
            ->assertSee('Default Landing Page')
            ->assertFormSet(['landing_page' => 'dashboard']);
    });

    test('can change the landing page to people', function (): void {
        Livewire::test(UpdateLandingPageComponent::class)
            ->fillForm(['landing_page' => 'people'])
            ->call('updateLandingPage')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($this->user->fresh()->landing_page)->toBe('people');
    });

    test('persists the chosen landing page across remounts', function (): void {
        Livewire::test(UpdateLandingPageComponent::class)
            ->fillForm(['landing_page' => 'notes'])
            ->call('updateLandingPage')
            ->assertHasNoFormErrors();

        Livewire::test(UpdateLandingPageComponent::class)
            ->assertFormSet(['landing_page' => 'notes']);
    });

    test('rejects an invalid landing page value', function (): void {
        Livewire::test(UpdateLandingPageComponent::class)
            ->fillForm(['landing_page' => 'not-a-page'])
            ->call('updateLandingPage')
            ->assertHasFormErrors(['landing_page']);
    });
});
