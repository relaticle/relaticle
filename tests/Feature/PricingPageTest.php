<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use Laravel\Pennant\Feature;

it('shows the legacy two-card page when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertDontSee('Cloud Pro');
});

it('shows the pro tier when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertSee('$19')
        ->assertSee('$24')
        ->assertSee('$228 billed yearly')
        ->assertSee('Save 21%')
        ->assertSee('2,000 AI credits')
        ->assertSee('Cloud Pro')
        ->assertSee('Self-Hosted')
        ->assertSee('Start your 14-day trial')
        ->assertDontSee('One workspace price as your team grows')
        ->assertDontSee('300 AI credits')
        ->assertDontSee('Generous free tier');
});
