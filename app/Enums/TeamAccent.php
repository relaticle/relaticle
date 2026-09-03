<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Accent palettes a workspace can pick, mirroring the Hermes Web UI skin
 * concept: each is a single seed color Filament expands into a full 50-950
 * ramp via Filament\Support\Colors\Color::hex(). `null` means the brand
 * default (BrandColors::primary()).
 */
enum TeamAccent: string
{
    case Ares = '#C0392B';
    case Slate = '#475569';
    case Poseidon = '#0369A1';
    case Sisyphus = '#7C3AED';
    case Charizard = '#EA580C';
    case Sienna = '#D97757';
    case Mono = '#666666';
    case Nous = '#4682B4';

    public function label(): string
    {
        return __("teams.accent_colors.{$this->name}");
    }
}
