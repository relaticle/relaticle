<?php

declare(strict_types=1);

use App\Features\Billing;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    $this->withVite();

    Feature::for(null)->activate(Billing::class);
});

it('keeps each plan tagline on one line in the narrowest three-column layout', function (): void {
    $page = $this->visit('/pricing')->resize(1024, 900);

    $lines = $page->script(<<<'JS'
        ['#pro-plan', '#scale-plan', '#enterprise-plan']
            .map((plan) => document.querySelector(`${plan} p.mt-1\\.5`))
            .map((tagline) => Math.round(tagline.getBoundingClientRect().height / parseFloat(getComputedStyle(tagline).lineHeight)))
    JS);

    expect($lines)->toHaveCount(3)
        ->each->toBe(1);
});
