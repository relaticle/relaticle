<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Decimal separator format for CSV import parsing.
 *
 * only asks about decimal separator,
 * thousands separators are stripped automatically.
 */
enum NumberFormat: string implements HasLabel
{
    case POINT = 'point';
    case COMMA = 'comma';

    public function getLabel(): string
    {
        return match ($this) {
            self::POINT => 'Point',
            self::COMMA => 'Comma',
        };
    }

    /**
     * Get example pattern for UI display
     */
    public function getExample(): string
    {
        return match ($this) {
            self::POINT => '1,000.00',
            self::COMMA => '1.000,00',
        };
    }

    /**
     * Parse a string value into a float.
     *
     * Strips non-numeric characters except the decimal separator,
     * then normalizes to PHP float format.
     */
    public function parse(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $decimalSeparator = match ($this) {
            self::POINT => '.',
            self::COMMA => ',',
        };

        $otherSeparator = match ($this) {
            self::POINT => ',',
            self::COMMA => '.',
        };

        $value = str_replace(' ', '', $value);
        $value = str_replace($otherSeparator, '', $value);

        if ($decimalSeparator === ',') {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Format a float value for display.
     */
    public function format(float $value, int $decimals = 2): string
    {
        return match ($this) {
            self::POINT => number_format($value, $decimals, '.', ''),
            self::COMMA => number_format($value, $decimals, ',', ''),
        };
    }

    /**
     * Get select options for UI display.
     *
     * @return array<string, array{value: string, label: string, description: string}>
     */
    public static function toOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = [
                'value' => $case->value,
                'label' => $case->getLabel(),
                'description' => $case->getExample(),
            ];
        }

        return $options;
    }
}
