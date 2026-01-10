<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Relaticle\ImportWizard\Data\DateFormatResult;
use Relaticle\ImportWizard\Enums\DateFormat;

/**
 * Analyzes sample cell values to infer field types.
 * Used as a FALLBACK when header matching fails.
 *
 * @phpstan-type InferenceResult array{type: string|null, confidence: float, suggestedFields: array<string>}
 */
final class DataTypeInferencer
{
    private const string EMAIL_PATTERN = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';

    private const string PHONE_PATTERN = '/^[\+]?\d?[-.\s]?\(?\d{1,4}\)?[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{0,4}$/';

    private const string URL_PATTERN = '/^https?:\/\/[^\s]+$/i';

    private const string DOMAIN_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/';

    // Matches: 2024-01-15, 2024-01-15T10:30:00, 2024-01-15 14:45, 01/15/2024, 1/15/24
    private const string DATE_PATTERN = '/^(\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?|\d{1,2}\/\d{1,2}\/\d{2,4})$/';

    private const float MIN_CONFIDENCE = 0.7;

    /** @var array<string, array{pattern: string, fields: array<string>}> */
    private const array TYPE_MAPPINGS = [
        'email' => [
            'pattern' => self::EMAIL_PATTERN,
            'fields' => ['email', 'work_email', 'personal_email', 'account_owner_email'],
        ],
        'url' => [
            'pattern' => self::URL_PATTERN,
            'fields' => ['website', 'linkedin', 'url', 'link'],
        ],
        // Date must be checked BEFORE phone because phone pattern is permissive and matches dates
        'date' => [
            'pattern' => self::DATE_PATTERN,
            'fields' => ['date', 'created_at', 'updated_at', 'birth_date', 'start_date', 'end_date', 'due_date', 'close_date'],
        ],
        'phone' => [
            'pattern' => self::PHONE_PATTERN,
            'fields' => ['phone', 'mobile', 'work_phone', 'home_phone'],
        ],
        'domain' => [
            'pattern' => self::DOMAIN_PATTERN,
            'fields' => ['domain', 'company_domain'],
        ],
    ];

    /**
     * Infer field type from sample values.
     *
     * @param  array<mixed>  $sampleValues
     * @return InferenceResult
     */
    public function inferType(array $sampleValues): array
    {
        $nonEmptyValues = array_filter(
            $sampleValues,
            fn (mixed $v): bool => trim((string) $v) !== ''
        );

        if (count($nonEmptyValues) < 3) {
            return ['type' => null, 'confidence' => 0.0, 'suggestedFields' => []];
        }

        foreach (self::TYPE_MAPPINGS as $type => $config) {
            /** @var int */
            $matches = 0;

            foreach ($nonEmptyValues as $value) {
                if (preg_match($config['pattern'], trim((string) $value)) === 1) {
                    $matches++;
                }
            }

            /** @var float */
            $confidence = $matches / count($nonEmptyValues);

            if ($confidence >= self::MIN_CONFIDENCE) {
                return [
                    'type' => $type,
                    'confidence' => $confidence,
                    'suggestedFields' => $config['fields'],
                ];
            }
        }

        return ['type' => null, 'confidence' => 0.0, 'suggestedFields' => []];
    }

    /**
     * Detect the most likely date format from sample values.
     *
     * Analyzes values to find unambiguous evidence:
     * - ISO format (YYYY-MM-DD) is always unambiguous
     * - DD > 12 indicates European (DD/MM/YYYY)
     * - Second position > 12 indicates American (MM/DD/YYYY)
     * - Both positions <= 12 is ambiguous
     *
     * @param  array<string>  $values  Unique date values from the column
     */
    public function detectDateFormat(array $values): DateFormatResult
    {
        $counts = collect($values)
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->map(fn (string $value): string => $this->classifyDateValue($value))
            ->countBy()
            ->all();

        /** @var int */
        $isoCount = $counts['iso'] ?? 0;
        /** @var int */
        $europeanOnlyCount = $counts['european_only'] ?? 0;
        /** @var int */
        $americanOnlyCount = $counts['american_only'] ?? 0;
        /** @var int */
        $ambiguousCount = $counts['ambiguous'] ?? 0;
        /** @var int */
        $invalidCount = $counts['invalid'] ?? 0;

        $totalAnalyzed = $isoCount + $europeanOnlyCount + $americanOnlyCount + $ambiguousCount + $invalidCount;

        if ($totalAnalyzed === 0) {
            return DateFormatResult::forAmbiguous(0);
        }

        // Determine the most likely format based on evidence
        $detectedFormat = $this->determineFormat(
            isoCount: $isoCount,
            europeanOnlyCount: $europeanOnlyCount,
            americanOnlyCount: $americanOnlyCount,
        );

        $confidence = $this->calculateConfidence(
            detectedFormat: $detectedFormat,
            isoCount: $isoCount,
            europeanOnlyCount: $europeanOnlyCount,
            americanOnlyCount: $americanOnlyCount,
            ambiguousCount: $ambiguousCount,
            totalAnalyzed: $totalAnalyzed,
        );

        return new DateFormatResult(
            detectedFormat: $detectedFormat,
            confidence: $confidence,
            isoCount: $isoCount,
            europeanOnlyCount: $europeanOnlyCount,
            americanOnlyCount: $americanOnlyCount,
            ambiguousCount: $ambiguousCount,
            invalidCount: $invalidCount,
            totalAnalyzed: $totalAnalyzed,
        );
    }

    /**
     * Classify a single date value.
     *
     * @return 'iso'|'european_only'|'american_only'|'ambiguous'|'invalid'
     */
    private function classifyDateValue(string $value): string
    {
        // Check for ISO format (YYYY-MM-DD with optional time)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $matches)) {
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return 'iso';
            }

            return 'invalid';
        }

        // Check for slash-separated format (could be DD/MM or MM/DD)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $value, $matches)) {
            return $this->classifyDayMonthPair((int) $matches[1], (int) $matches[2]);
        }

        // Check for dash-separated format (could be DD-MM or MM-DD)
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $value, $matches)) {
            return $this->classifyDayMonthPair((int) $matches[1], (int) $matches[2]);
        }

        return 'invalid';
    }

    /**
     * Classify a day/month pair to determine format.
     *
     * @return 'european_only'|'american_only'|'ambiguous'|'invalid'
     */
    private function classifyDayMonthPair(int $first, int $second): string
    {
        $firstValidAsMonth = $first >= 1 && $first <= 12;
        $secondValidAsMonth = $second >= 1 && $second <= 12;
        $firstValidAsDay = $first >= 1 && $first <= 31;
        $secondValidAsDay = $second >= 1 && $second <= 31;

        // First > 12 means it must be the day (European format DD/MM)
        if (! $firstValidAsMonth && $secondValidAsMonth && $firstValidAsDay) {
            return 'european_only';
        }

        // Second > 12 means it must be the day (American format MM/DD)
        if ($firstValidAsMonth && ! $secondValidAsMonth && $secondValidAsDay) {
            return 'american_only';
        }

        // Both could be valid as months (ambiguous)
        if ($firstValidAsMonth && $secondValidAsMonth) {
            return 'ambiguous';
        }

        return 'invalid';
    }

    /**
     * Determine the most likely format based on evidence.
     */
    private function determineFormat(
        int $isoCount,
        int $europeanOnlyCount,
        int $americanOnlyCount,
    ): DateFormat {
        // ISO takes precedence if present and no conflicting evidence
        if ($isoCount > 0 && $europeanOnlyCount === 0 && $americanOnlyCount === 0) {
            return DateFormat::ISO;
        }

        // Clear European evidence
        if ($europeanOnlyCount > 0 && $americanOnlyCount === 0) {
            return DateFormat::EUROPEAN;
        }

        // Clear American evidence
        if ($americanOnlyCount > 0 && $europeanOnlyCount === 0) {
            return DateFormat::AMERICAN;
        }

        // Conflicting evidence or all ambiguous - default to ISO as safest
        return DateFormat::ISO;
    }

    /**
     * Calculate confidence score for the detected format.
     */
    private function calculateConfidence(
        DateFormat $detectedFormat,
        int $isoCount,
        int $europeanOnlyCount,
        int $americanOnlyCount,
        int $ambiguousCount,
        int $totalAnalyzed,
    ): float {
        if ($totalAnalyzed === 0) {
            return 0.0;
        }

        // ISO-only: high confidence
        if ($detectedFormat === DateFormat::ISO && $isoCount > 0 && $europeanOnlyCount === 0 && $americanOnlyCount === 0) {
            return 1.0;
        }

        // Clear unambiguous evidence for European
        if ($detectedFormat === DateFormat::EUROPEAN && $europeanOnlyCount > 0 && $americanOnlyCount === 0) {
            $unambiguousRatio = ($isoCount + $europeanOnlyCount) / $totalAnalyzed;

            return min(0.95, 0.7 + ($unambiguousRatio * 0.25));
        }

        // Clear unambiguous evidence for American
        if ($detectedFormat === DateFormat::AMERICAN && $americanOnlyCount > 0 && $europeanOnlyCount === 0) {
            $unambiguousRatio = ($isoCount + $americanOnlyCount) / $totalAnalyzed;

            return min(0.95, 0.7 + ($unambiguousRatio * 0.25));
        }

        // Conflicting evidence
        if ($europeanOnlyCount > 0 && $americanOnlyCount > 0) {
            return 0.2;
        }

        // All ambiguous - low confidence
        if ($ambiguousCount > 0 && $isoCount === 0 && $europeanOnlyCount === 0 && $americanOnlyCount === 0) {
            return 0.3;
        }

        return 0.5;
    }
}
