<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class MultiValueAddPlaceholder
{
    public static function make(string $inputType, string $addLabel): string
    {
        $label = match ($inputType) {
            'email' => __('filament/inline-edit.add_email'),
            default => self::withoutTrailingDots($addLabel),
        };

        if ($label === '') {
            $label = __('filament/inline-edit.add_value');
        }

        return $label.'...';
    }

    private static function withoutTrailingDots(string $label): string
    {
        $label = trim($label);

        while (str_ends_with($label, '...')) {
            $label = rtrim(substr($label, 0, -3));
        }

        return $label;
    }
}
