<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Carbon\Carbon;
use Filament\Support\Contracts\HasLabel;

/**
 * Supported date/datetime formats for CSV import parsing.
 *
 * Handles both date-only and datetime values with a unified interface.
 * The format determines how ambiguous dates (like 01/02/2024) are interpreted.
 */
enum DateFormat: string implements HasLabel
{
    case ISO = 'iso';
    case EUROPEAN = 'european';
    case AMERICAN = 'american';

    public function getLabel(): string
    {
        return match ($this) {
            self::ISO => 'ISO standard',
            self::EUROPEAN => 'European',
            self::AMERICAN => 'American',
        };
    }

    /**
     * Get example patterns for UI display.
     *
     * @return array<string>
     */
    public function getExamples(bool $withTime = false): array
    {
        if ($withTime) {
            return match ($this) {
                self::ISO => ['2024-05-15 16:00:00'],
                self::EUROPEAN => ['16:00 15-05-2024', '21:30:02 15 May 2024'],
                self::AMERICAN => ['16:00 05-15-2024', '21:30:02 May 15th 2024'],
            };
        }

        return match ($this) {
            self::ISO => ['2024-05-15'],
            self::EUROPEAN => ['15-05-2024', '15/05/2024', '15 May 2024'],
            self::AMERICAN => ['05-15-2024', '05/15/2024', 'May 15th 2024'],
        };
    }

    /**
     * Format a Carbon instance for display.
     */
    public function format(Carbon $date, bool $withTime = false): string
    {
        if ($withTime) {
            return match ($this) {
                self::ISO => $date->format('Y-m-d H:i:s'),
                self::EUROPEAN => $date->format('H:i:s d/m/Y'),
                self::AMERICAN => $date->format('H:i:s m/d/Y'),
            };
        }

        return match ($this) {
            self::ISO => $date->format('Y-m-d'),
            self::EUROPEAN => $date->format('d/m/Y'),
            self::AMERICAN => $date->format('m/d/Y'),
        };
    }

    /**
     * @return array<string, array{value: string, label: string, description: string}>
     */
    public static function toOptions(bool $withTime = false): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = [
                'value' => $case->value,
                'label' => $case->getLabel(),
                'description' => implode(', ', $case->getExamples($withTime)),
            ];
        }

        return $options;
    }

    /**
     * Parse a date string into a Carbon instance.
     *
     * Attempts multiple format variations to handle real-world CSV data.
     */
    public function parse(string $value, bool $withTime = false): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ($this->getParseFormats($withTime) as $format) {
            try {
                $date = \Illuminate\Support\Facades\Date::createFromFormat($format, $value);

                if ($date instanceof Carbon) {
                    return $date;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * Format a Carbon instance for use in HTML date/datetime-local input.
     */
    public function toPickerValue(Carbon $date, bool $withTime = false): string
    {
        return $date->format($withTime ? 'Y-m-d\TH:i' : 'Y-m-d');
    }

    /**
     * Get the parse formats to attempt for this date format.
     *
     * @return array<string>
     */
    private function getParseFormats(bool $withTime): array
    {
        if ($withTime) {
            return match ($this) {
                self::ISO => [
                    'Y-m-d H:i:s',
                    'Y-m-d\TH:i:s',
                    'Y-m-d\TH:i',
                    'Y-m-d H:i',
                ],
                self::EUROPEAN => [
                    'd/m/Y H:i:s',
                    'd-m-Y H:i:s',
                    'd.m.Y H:i:s',
                    'j/n/Y H:i:s',
                    'd/m/Y H:i',
                    'd-m-Y H:i',
                    'd.m.Y H:i',
                    'j/n/Y H:i',
                    'H:i:s d/m/Y',
                    'H:i:s d-m-Y',
                    'H:i d/m/Y',
                    'H:i d-m-Y',
                ],
                self::AMERICAN => [
                    'm/d/Y H:i:s',
                    'm-d-Y H:i:s',
                    'n/j/Y H:i:s',
                    'm/d/Y H:i',
                    'm-d-Y H:i',
                    'n/j/Y H:i',
                    'H:i:s m/d/Y',
                    'H:i:s m-d-Y',
                    'H:i m/d/Y',
                    'H:i m-d-Y',
                ],
            };
        }

        return match ($this) {
            self::ISO => [
                'Y-m-d',
            ],
            self::EUROPEAN => [
                'd/m/Y',
                'd-m-Y',
                'd.m.Y',
                'j/n/Y',
                'j-n-Y',
                'j.n.Y',
            ],
            self::AMERICAN => [
                'm/d/Y',
                'm-d-Y',
                'n/j/Y',
                'n-j-Y',
            ],
        };
    }
}
