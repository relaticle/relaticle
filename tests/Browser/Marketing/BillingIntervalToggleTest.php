<?php

declare(strict_types=1);

use App\Features\Billing;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    $this->withVite();

    Feature::for(null)->activate(Billing::class);
});

it('rings the billing interval only for keyboard focus', function (): void {
    $page = $this->visit('/pricing')->resize(1440, 900);

    $page->click('[role=radiogroup] label:nth-child(2)');

    $outline = fn (int $index): string => $page->script("getComputedStyle(document.querySelectorAll('[role=radiogroup] label')[{$index}]).outlineStyle");

    expect($outline(1))->toBe('none');

    $page->keys('[role=radiogroup] input[value=monthly]', ['ArrowLeft']);

    expect($outline(0))->toBe('solid');

    $page->assertNoJavaScriptErrors();
});
