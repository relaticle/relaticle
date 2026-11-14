<?php

declare(strict_types=1);

namespace App\Enums;

enum OptionColor: string
{
    case Red = '#ef4444';
    case Orange = '#f97316';
    case Amber = '#f59e0b';
    case Lime = '#84cc16';
    case Green = '#22c55e';
    case Cyan = '#06b6d4';
    case Blue = '#3b82f6';
    case Magenta = '#d946ef';
    case Purple = '#a855f7';
    case Pink = '#ec4899';
    case Olive = '#808a3c';
    case Gray = '#6b7280';

    public function label(): string
    {
        return __('filament/pages/boards.stage_menu.colors.'.strtolower($this->name));
    }

    public static function fromInput(string $input): ?self
    {
        $input = strtolower(trim($input));

        foreach (self::cases() as $case) {
            if ($input === $case->value || $input === strtolower($case->name)) {
                return $case;
            }
        }

        return null;
    }
}
