<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Filament resolves colors per panel, so a view rendered outside panel
 * middleware falls back to its default amber unless the same palette is
 * registered globally. This is the single source both registrations read.
 */
final readonly class BrandColors
{
    /**
     * @return array<int|string, string>
     */
    public static function primary(): array
    {
        return [
            50 => 'oklch(0.969 0.014 279.186)',
            100 => 'oklch(0.943 0.024 279.186)',
            200 => 'oklch(0.894 0.048 279.186)',
            300 => 'oklch(0.812 0.088 279.186)',
            400 => 'oklch(0.711 0.139 279.186)',
            500 => 'oklch(0.609 0.191 279.186)',
            600 => 'oklch(0.544 0.216 279.186)',
            700 => 'oklch(0.494 0.210 279.186)',
            800 => 'oklch(0.435 0.184 279.186)',
            900 => 'oklch(0.383 0.155 279.186)',
            950 => 'oklch(0.285 0.118 279.186)',
            'DEFAULT' => 'oklch(0.544 0.216 279.186)',
        ];
    }
}
