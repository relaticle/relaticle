<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Carbon\Carbon;
use Filament\Support\Contracts\HasLabel;

/**
 * Supported date formats for CSV import parsing.
 */
enum DateFormat: string implements HasLabel
{
    /**
     * ISO 8601 standard format (YYYY-MM-DD).
     * Unambiguous and internationally recognized.
     */
    case ISO = 'iso';

    /**
     * European format (DD/MM/YYYY).
     * Day comes first, common in UK, EU, and most of the world.
     */
    case EUROPEAN = 'european';

    /**
     * American format (MM/DD/YYYY).
     * Month comes first, used primarily in the United States.
     */
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
     * Get label with example patterns for UI display.
     * Format: "European (15-05-2024 • 15/05/2024 • 15 May 2024)"
     */
    public function getLabelWithExamples(): string
    {
        $examples = implode(' • ', $this->getExamples());

        return "{$this->getLabel()} ({$examples})";
    }

    /**
     * Get example patterns for UI display (like Attio).
     *
     * @return array<string>
     */
    public function getExamples(): array
    {
        return match ($this) {
            self::ISO => ['2024-05-15'],
            self::EUROPEAN => ['15-05-2024', '15/05/2024', '15 May 2024'],
            self::AMERICAN => ['05-15-2024', '05/15/2024', 'May 15th 2024'],
        };
    }

    /**
     * Get PHP date formats to try when parsing.
     *
     * @return array<string>
     */
    public function getPhpFormats(): array
    {
        return match ($this) {
            self::ISO => ['Y-m-d'],
            // Slash formats first to maintain format() output compatibility
            self::EUROPEAN => ['d/m/Y', 'd-m-Y', 'j F Y', 'd M Y', 'j M Y'],
            self::AMERICAN => ['m/d/Y', 'm-d-Y', 'F jS Y', 'M d Y', 'F j Y', 'M jS Y'],
        };
    }

    /**
     * Get regex pattern to detect this format.
     */
    public function getPattern(): string
    {
        return match ($this) {
            self::ISO => '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
            self::EUROPEAN => '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/',
            self::AMERICAN => '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/',
        };
    }

    /**
     * Parse a date string, trying all accepted formats.
     *
     * @return Carbon|null Returns parsed Carbon instance or null if parsing fails
     */
    public function parse(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Handle 2-digit years FIRST (before 4-digit formats match incorrectly)
        if ($this !== self::ISO) {
            // Check for slash format with 2-digit year
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $value)) {
                $shortFormat = $this === self::EUROPEAN ? 'd/m/y' : 'm/d/y';
                $parsed = $this->tryParseWithFormat($value, $shortFormat);
                if ($parsed instanceof \Carbon\Carbon) {
                    return $parsed->startOfDay();
                }
            }

            // Check for dash format with 2-digit year
            if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2})$/', $value)) {
                $shortFormat = $this === self::EUROPEAN ? 'd-m-y' : 'm-d-y';
                $parsed = $this->tryParseWithFormat($value, $shortFormat);
                if ($parsed instanceof \Carbon\Carbon) {
                    return $parsed->startOfDay();
                }
            }
        }

        // Try each standard format
        foreach ($this->getPhpFormats() as $format) {
            $parsed = $this->tryParseWithFormat($value, $format);
            if ($parsed instanceof \Carbon\Carbon) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }

    /**
     * Try to parse a value with a specific format.
     */
    private function tryParseWithFormat(string $value, string $format): ?Carbon
    {
        return \Illuminate\Support\Facades\Date::canBeCreatedFromFormat($value, $format)
            ? \Illuminate\Support\Facades\Date::createFromFormat($format, $value)
            : null;
    }

    /**
     * Format a Carbon date using this format for display.
     */
    public function format(Carbon $date): string
    {
        return $date->format($this->getPhpFormats()[0]);
    }

    /**
     * Check if a value matches this format's pattern.
     */
    public function matches(string $value): bool
    {
        return (bool) preg_match($this->getPattern(), trim($value));
    }

    /**
     * Analyze a value to determine if it can ONLY be parsed as this format.
     *
     * Returns true if parsing as other formats would fail or produce different results.
     */
    public function isUnambiguousFor(string $value): bool
    {
        if ($this === self::ISO) {
            // ISO is always unambiguous if it matches
            return $this->matches($value);
        }

        // For European/American, check if the value can only work in one format
        if (! preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $value, $matches)) {
            return false;
        }

        $first = (int) $matches[1];
        $second = (int) $matches[2];

        // European is unambiguous when first (day) > 12
        // American is unambiguous when second (day) > 12
        return $this === self::EUROPEAN
            ? ($first > 12 && $second <= 12)
            : ($second > 12 && $first <= 12);
    }

    /**
     * Check if a value is ambiguous (could be parsed as either European or American).
     */
    public static function isAmbiguous(string $value): bool
    {
        if (! preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $value, $matches)) {
            return false;
        }

        $first = (int) $matches[1];
        $second = (int) $matches[2];

        // Ambiguous when both positions could be valid as month (1-12)
        return $first >= 1 && $first <= 12 && $second >= 1 && $second <= 12;
    }
}
