<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Carbon\Carbon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Date;

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
     *
     * A CSV carries no offset, so a naive datetime means the wall clock where the person
     * who exported it lives — the same thing it means when they type it into the form,
     * which converts out of their zone before storing. `$timezone` is that zone; parsing
     * in it keeps the two paths on the same instant. Null keeps the PHP default, for
     * callers with no user in scope.
     *
     * Only pass a zone when `$withTime` is true. A date has no time of day to convert,
     * and interpreting midnight in a negative-offset zone would move it to the previous
     * calendar day.
     */
    public function parse(string $value, bool $withTime = false, ?string $timezone = null): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ($this->getParseFormats($withTime) as $format) {
            try {
                $date = Date::createFromFormat($format, $value, $withTime ? $timezone : null);

                if ($date instanceof Carbon) {
                    return $withTime && $timezone !== null ? $date->utc() : $date;
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
