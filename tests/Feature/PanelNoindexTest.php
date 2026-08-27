<?php

declare(strict_types=1);

use Filament\Facades\Filament;

it('marks app panel pages noindex', function (): void {
    $response = $this->get(Filament::getPanel('app')->getLoginUrl());

    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('marks sysadmin panel pages noindex', function (): void {
    $response = $this->get(Filament::getPanel('sysadmin')->getLoginUrl());

    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('keeps marketing pages indexable', function (): void {
    $this->get('/pricing')->assertHeaderMissing('X-Robots-Tag');
});
