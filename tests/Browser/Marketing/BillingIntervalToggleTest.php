<?php

declare(strict_types=1);

use App\Features\Billing;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    $this->withVite();

    Feature::for(null)->activate(Billing::class);
});

it('leaves no focus ring on the billing interval after a mouse click', function (): void {
    $page = $this->visit('/pricing')->resize(1440, 900);

    $page->click('[role=radiogroup] label:nth-child(2)');

    expect($page->script("getComputedStyle(document.querySelectorAll('[role=radiogroup] label')[1]).outlineStyle"))->toBe('none');

    $page->assertNoJavaScriptErrors();
});
