<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\DateFormat;
use Spatie\LaravelData\Data;

/**
 * Result of date format detection for a CSV column.
 */
final class DateFormatResult extends Data
{
    public function __construct(
        public readonly DateFormat $detectedFormat,
        public readonly float $confidence,
        public readonly int $isoCount,
        public readonly int $europeanOnlyCount,
        public readonly int $americanOnlyCount,
        public readonly int $ambiguousCount,
        public readonly int $invalidCount,
        public readonly int $totalAnalyzed,
    ) {}

    /**
     * Create a result for ISO-only dates.
     */
    public static function forIsoOnly(int $count): self
    {
        return new self(
            detectedFormat: DateFormat::ISO,
            confidence: 1.0,
            isoCount: $count,
            europeanOnlyCount: 0,
            americanOnlyCount: 0,
            ambiguousCount: 0,
            invalidCount: 0,
            totalAnalyzed: $count,
        );
    }

    /**
     * Create a result for fully ambiguous dates with a default format.
     */
    public static function forAmbiguous(int $count, DateFormat $defaultFormat = DateFormat::ISO): self
    {
        return new self(
            detectedFormat: $defaultFormat,
            confidence: $count === 0 ? 0.0 : 0.3,
            isoCount: 0,
            europeanOnlyCount: 0,
            americanOnlyCount: 0,
            ambiguousCount: $count,
            invalidCount: 0,
            totalAnalyzed: $count,
        );
    }
}
