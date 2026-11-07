<?php

declare(strict_types=1);

use Filament\Facades\Filament;

it('links the web app manifest so a home-screen install opens standalone', function (): void {
    $this->get(Filament::getPanel('app')->getLoginUrl())
        ->assertSuccessful()
        ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', escape: false)
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', escape: false)
        ->assertSee('<meta name="theme-color"', escape: false);

    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->not->toBeEmpty();
});

it('ships an install icon and a maskable icon at every size the manifest declares', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest['icons'] as $icon) {
        [$width, $height, $type] = getimagesize(public_path(ltrim((string) $icon['src'], '/')));

        expect("{$width}x{$height}")->toBe($icon['sizes'])
            ->and($type)->toBe(IMAGETYPE_PNG);
    }

    expect(collect($manifest['icons'])->groupBy('purpose')->map->pluck('sizes')->toArray())->toBe([
        'any' => ['192x192', '512x512'],
        'maskable' => ['192x192', '512x512'],
    ]);
});
