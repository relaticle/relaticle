<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use Laravel\Pennant\Feature;

it('shows the legacy two-card page when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertDontSee('$29');
});

it('shows the pro tier when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertSee('$29')
        ->assertSee('2,000 AI credits')
        ->assertSee('300 AI credits')
        ->assertSee('Try Pro free for 14 days');
});
