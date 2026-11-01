<?php

declare(strict_types=1);

use Filament\Facades\Filament;

it('links the web app manifest so a home-screen install opens standalone', function (): void {
    $this->get(Filament::getPanel('app')->getLoginUrl())
        ->assertSuccessful()
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', escape: false)
        ->assertSee('<meta name="theme-color"', escape: false);

    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->not->toBeEmpty();
});
