<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\UiSurface;
use Relaticle\CustomFields\Support\ViewFlavor;

it('offers the field types as the dropdown they were before 4.0', function (): void {
    expect(ViewFlavor::view(UiSurface::TypePicker))->toBeNull();
});

it('leaves every other custom fields surface on the 4.0 presentation', function (): void {
    $surfaces = array_filter(
        UiSurface::cases(),
        fn (UiSurface $surface): bool => $surface !== UiSurface::TypePicker,
    );

    foreach ($surfaces as $surface) {
        expect(ViewFlavor::view($surface))->toBe("custom-fields::flavors.polished.{$surface->value}");
    }
});
