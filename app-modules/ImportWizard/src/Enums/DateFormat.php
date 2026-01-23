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
     * Get label with example patterns for UI display.
     */
    public function getLabelWithExamples(bool $withTime = false): string
    {
        $examples = implode(' • ', $this->getExamples($withTime));

        return "{$this->getLabel()} ({$examples})";
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
}
