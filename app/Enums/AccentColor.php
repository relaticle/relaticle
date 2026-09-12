<?php

declare(strict_types=1);

namespace App\Enums;

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
