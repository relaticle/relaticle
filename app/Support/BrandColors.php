<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The product's brand palette, shared by the app panel and by any Filament
 * rendering that happens outside a panel.
 *
 * Filament resolves colors from a per-panel registry, so a view rendered
 * outside panel middleware (the invitation and join interstitials, the
 * scheduled-deletion interstitial) falls back to Filament's own default amber
 * unless the same palette is registered globally. Keeping the single source
 * here is what stops those pages from shipping in a different brand color
 * than the panel they lead into.
 */
final readonly class BrandColors
{
    /**
     * @return array<int|string, string>
     */
    public static function primary(): array
    {
        return [
            50 => 'oklch(0.969 0.016 293.756)',
            100 => 'oklch(0.943 0.028 294.588)',
            200 => 'oklch(0.894 0.055 293.283)',
            300 => 'oklch(0.811 0.101 293.571)',
            400 => 'oklch(0.709 0.159 293.541)',
            500 => 'oklch(0.606 0.219 292.717)',
            600 => 'oklch(0.541 0.247 293.009)',
            700 => 'oklch(0.491 0.241 292.581)',
            800 => 'oklch(0.432 0.211 292.759)',
            900 => 'oklch(0.380 0.178 293.745)',
            950 => 'oklch(0.283 0.135 291.089)',
            'DEFAULT' => 'oklch(0.541 0.247 293.009)',
        ];
    }
}
