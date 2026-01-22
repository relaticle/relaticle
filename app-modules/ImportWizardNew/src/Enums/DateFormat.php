<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Enums;

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
     * Get label with example patterns for UI display.
     */
    public function getLabelWithExamples(bool $withTime = false): string
    {
        $examples = implode(' • ', $this->getExamples($withTime));

        return "{$this->getLabel()} ({$examples})";
    }

    /**
     * Parse a date or datetime string using this format's patterns.
     */
    public function parse(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $hasTime = $this->valueContainsTime($value);

        $formats = $hasTime
            ? [...$this->getDateTimePhpFormats(), ...$this->getDatePhpFormats()]
            : [...$this->getDatePhpFormats(), ...$this->getDateTimePhpFormats()];

        foreach ($formats as $format) {
            if (Date::canBeCreatedFromFormat($value, $format)) {
                $parsed = Date::createFromFormat($format, $value);

                return $hasTime ? $parsed : $parsed?->startOfDay();
            }
        }

        // Handle 2-digit years for European/American
        if ($this !== self::ISO) {
            $parsed = $this->tryParseTwoDigitYear($value);
            if ($parsed instanceof Carbon) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Parse a value for display, handling correction vs raw logic.
     *
     * Corrections are stored in ISO format (from HTML5 picker).
     * Raw values use the user's selected format.
     *
     * @return array{parsed: ?Carbon, pickerValue: string, formattedDisplay: string}
     */
    public function parseForDisplay(
        string $rawValue,
        ?string $mappedValue,
        bool $hasCorrection,
        bool $isDateTime,
    ): array {
        if ($hasCorrection && $mappedValue !== null) {
            $parseFormat = self::ISO;
            $valueToFormat = $mappedValue;
        } else {
            $parseFormat = $this;
            $valueToFormat = $rawValue;
        }

        $parsed = $valueToFormat !== ''
            ? $parseFormat->parse($valueToFormat)
            : null;

        // HTML5 picker needs ISO format
        $pickerValue = $parsed instanceof \Carbon\Carbon
            ? ($isDateTime ? $parsed->format('Y-m-d\TH:i') : $parsed->format('Y-m-d'))
            : '';

        // Format for display in user's selected format
        $formattedDisplay = $parsed instanceof \Carbon\Carbon ? $this->format($parsed, $isDateTime) : '';

        return [
            'parsed' => $parsed,
            'pickerValue' => $pickerValue,
            'formattedDisplay' => $formattedDisplay,
        ];
    }

    /**
     * Normalize HTML5 datetime-local picker value to database format.
     *
     * Converts "2024-05-15T16:00" → "2024-05-15 16:00:00"
     */
    public static function normalizePickerValue(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $trimmed)) {
            return str_replace('T', ' ', $trimmed).':00';
        }

        return $trimmed;
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
     * @return array<string>
     */
    private function getDatePhpFormats(): array
    {
        return match ($this) {
            self::ISO => ['Y-m-d'],
            self::EUROPEAN => ['d/m/Y', 'd-m-Y', 'j F Y', 'd M Y', 'j M Y'],
            self::AMERICAN => ['m/d/Y', 'm-d-Y', 'F jS Y', 'M d Y', 'F j Y', 'M jS Y'],
        };
    }

    /**
     * @return array<string>
     */
    private function getDateTimePhpFormats(): array
    {
        return match ($this) {
            self::ISO => [
                'Y-m-d H:i:s',
                'Y-m-d H:i',
                'Y-m-d\TH:i:s',
                'Y-m-d\TH:i',
                'Y-m-d\TH:i:sP',
            ],
            self::EUROPEAN => [
                'H:i d-m-Y',
                'H:i:s d-m-Y',
                'H:i d/m/Y',
                'H:i:s d/m/Y',
                'H:i:s j F Y',
                'H:i:s d M Y',
            ],
            self::AMERICAN => [
                'H:i m-d-Y',
                'H:i:s m-d-Y',
                'H:i m/d/Y',
                'H:i:s m/d/Y',
                'H:i:s F jS Y',
                'H:i:s M d Y',
            ],
        };
    }

    private function valueContainsTime(string $value): bool
    {
        // Check for time patterns: HH:MM or HH:MM:SS
        return (bool) preg_match('/\d{1,2}:\d{2}(:\d{2})?/', $value);
    }

    private function tryParseTwoDigitYear(string $value): ?Carbon
    {
        // Slash format with 2-digit year
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $value)) {
            $format = $this === self::EUROPEAN ? 'd/m/y' : 'm/d/y';
            if (Date::canBeCreatedFromFormat($value, $format)) {
                return Date::createFromFormat($format, $value)?->startOfDay();
            }
        }

        // Dash format with 2-digit year
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2})$/', $value)) {
            $format = $this === self::EUROPEAN ? 'd-m-y' : 'm-d-y';
            if (Date::canBeCreatedFromFormat($value, $format)) {
                return Date::createFromFormat($format, $value)?->startOfDay();
            }
        }

        return null;
    }
}
