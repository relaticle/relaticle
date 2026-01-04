<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Carbon\Carbon;
use Filament\Support\Contracts\HasLabel;

/**
 * Supported timestamp formats for CSV import parsing.
 *
 * IMPORTANT: Time position follows Attio's export format convention:
 * - ISO: time LAST (2024-05-15 16:00:00) - standard ISO 8601
 * - European/American: time FIRST (16:00 15-05-2024)
 *
 * The time-first convention for European/American formats differs from
 * common regional conventions where time typically appears after the date.
 * This design choice enables seamless migration from Attio exports.
 */
enum TimestampFormat: string implements HasLabel
{
    /**
     * ISO 8601 standard format with time LAST.
     * Example: 2024-05-15 16:00:00
     */
    case ISO = 'iso';

    /**
     * European format with time FIRST.
     * Example: 16:00 15-05-2024
     */
    case EUROPEAN = 'european';

    /**
     * American format with time FIRST.
     * Example: 16:00 05-15-2024
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
     * Get example patterns for UI display (like Attio).
     *
     * @return array<string>
     */
    public function getExamples(): array
    {
        return match ($this) {
            // ISO: time LAST
            self::ISO => ['2024-05-15 16:00:00'],
            // European: time FIRST
            self::EUROPEAN => ['16:00 15-05-2024', '21:30:02 15 May 2024'],
            // American: time FIRST
            self::AMERICAN => ['16:00 05-15-2024', '21:30:02 May 15th 2024'],
        };
    }

    /**
     * Get label with example patterns for UI display.
     * Format: "European (16:00 15-05-2024 • 21:30:02 15 May 2024)"
     */
    public function getLabelWithExamples(): string
    {
        $examples = implode(' • ', $this->getExamples());

        return "{$this->getLabel()} ({$examples})";
    }

    /**
     * Get PHP datetime formats to try when parsing.
     *
     * @return array<string>
     */
    public function getPhpFormats(): array
    {
        return match ($this) {
            // ISO: YYYY-MM-DD HH:MM:SS (time last)
            self::ISO => [
                'Y-m-d H:i:s',
                'Y-m-d H:i',
                'Y-m-d\TH:i:s',
                'Y-m-d\TH:i:sP',
                'Y-m-d\TH:i:s.u',
                'Y-m-d\TH:i:s.uP',
            ],
            // European: time FIRST, then date
            self::EUROPEAN => [
                'H:i d-m-Y',
                'H:i:s d-m-Y',
                'H:i d/m/Y',
                'H:i:s d/m/Y',
                'H:i:s j F Y',
                'H:i:s d M Y',
                'H:i j F Y',
            ],
            // American: time FIRST, then date
            self::AMERICAN => [
                'H:i m-d-Y',
                'H:i:s m-d-Y',
                'H:i m/d/Y',
                'H:i:s m/d/Y',
                'H:i:s F jS Y',
                'H:i:s M d Y',
                'H:i F jS Y',
            ],
        };
    }

    /**
     * Get the primary PHP format string for display formatting.
     */
    public function getPhpFormat(): string
    {
        return match ($this) {
            self::ISO => 'Y-m-d H:i:s',
            self::EUROPEAN => 'H:i:s d/m/Y',
            self::AMERICAN => 'H:i:s m/d/Y',
        };
    }

    /**
     * Parse a timestamp string, trying all accepted formats.
     *
     * @return Carbon|null Returns parsed Carbon instance or null if parsing fails
     */
    public function parse(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ($this->getPhpFormats() as $format) {
            $parsed = $this->tryParseWithFormat($value, $format);
            if ($parsed instanceof \Carbon\Carbon) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Try to parse a value with a specific format.
     */
    private function tryParseWithFormat(string $value, string $format): ?Carbon
    {
        return Carbon::canBeCreatedFromFormat($value, $format)
            ? Carbon::createFromFormat($format, $value)
            : null;
    }

    /**
     * Format a Carbon datetime using this format for display.
     */
    public function format(Carbon $datetime): string
    {
        return $datetime->format($this->getPhpFormat());
    }

    /**
     * Get corresponding DateFormat for date-only detection.
     */
    public function toDateFormat(): DateFormat
    {
        return DateFormat::from($this->value);
    }

    /**
     * Create from a DateFormat enum.
     */
    public static function fromDateFormat(DateFormat $dateFormat): self
    {
        return self::from($dateFormat->value);
    }
}
