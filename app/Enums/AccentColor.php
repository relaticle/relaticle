<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Accent seeds a member can pick for their own view of the app. Filament
 * expands the hex into a full 50-950 ramp via Color::hex(), so one seed is
 * enough. `null` means the brand default (BrandColors::primary()).
 */
enum AccentColor: string
{
    case Blue = '#2563EB';
    case Cyan = '#0891B2';
    case Amber = '#D97706';
    case Orange = '#EA580C';
    case Pink = '#DB2777';
    case Purple = '#9333EA';
    case Green = '#059669';
    case Slate = '#475569';

    public function label(): string
    {
        return __("appearance.accent_colors.{$this->name}");
    }
}
