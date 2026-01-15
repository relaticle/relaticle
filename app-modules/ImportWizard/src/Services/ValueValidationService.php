<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Carbon\Carbon;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ValueIssue;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\TimestampFormat;
use Relaticle\ImportWizard\Infrastructure\ImportCache;
use Relaticle\ImportWizard\Support\DateValidator;
use Relaticle\ImportWizard\Support\TimestampValidator;
use Throwable;

/**
 * Single source of truth for value validation in the ImportWizard.
 *
 * Consolidates validation logic that was previously duplicated in:
 * - CsvAnalyzer
 * - HasValueAnalysis trait
 * - ImportValuesController
 * - ImportCorrectionsController
 */
final readonly class ValueValidationService
{
    public function __construct(
        private DateValidator $dateValidator,
        private TimestampValidator $timestampValidator,
        private ImportCache $cache,
    ) {}

    /**
     * Validate a single value and return any issue.
     *
     * Used by API controllers when validating corrected values.
     *
     * @return array<string, mixed>|null Issue data array or null if valid
     */
    public function validateValue(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
        int $rowCount = 1,
    ): ?array {
        if ($value === '') {
            return null;
        }

        $result = $this->validateValueInternal($value, $fieldType, $formatValue, $rowCount);

        return $result['issue']?->toArray();
    }

    /**
     * Validate a single value and return the full result.
     *
     * @return array{valid: bool, parsed: ?Carbon, issue: ?ValueIssue}
     */
    public function validateValueFull(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
        int $rowCount = 1,
    ): array {
        if ($value === '') {
            return [
                'valid' => true,
                'parsed' => null,
                'issue' => null,
            ];
        }

        return $this->validateValueInternal($value, $fieldType, $formatValue, $rowCount);
    }

    /**
     * Validate all values in a column and return issues with counts.
     *
     * Used when date format changes to revalidate all values.
     *
     * @param  array<string, int>  $uniqueValues  Map of value => occurrence count
     * @return array{issues: array<ValueIssue>, errorCount: int, warningCount: int}
     */
    public function validateColumn(
        array $uniqueValues,
        ?string $fieldType,
        ?string $formatValue,
    ): array {
        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;
        $isDateField = $fieldType === FieldDataType::DATE->value || $isDateTimeField;

        if (! $isDateField || $formatValue === null) {
            return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
        }

        if ($isDateTimeField) {
            $format = TimestampFormat::tryFrom($formatValue);
            if ($format === null) {
                return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
            }
            $result = $this->timestampValidator->validateColumn($uniqueValues, $format);
        } else {
            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
            }
            $result = $this->dateValidator->validateColumn($uniqueValues, $format);
        }

        $issues = $result['issues'];
        $errorCount = collect($issues)->where('severity', 'error')->count();
        $warningCount = collect($issues)->where('severity', 'warning')->count();

        return [
            'issues' => $issues,
            'errorCount' => $errorCount,
            'warningCount' => $warningCount,
        ];
    }

    /**
     * Parse a date value and return a preview string.
     *
     * Used to show users how their dates will be interpreted.
     */
    public function parseDatePreview(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
    ): ?string {
        if ($value === '' || $formatValue === null) {
            return null;
        }

        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;

        try {
            if ($isDateTimeField) {
                $format = TimestampFormat::tryFrom($formatValue);
                if ($format === null) {
                    return null;
                }
                $parsed = $format->parse($value);

                return $parsed?->format('Y-m-d H:i:s');
            }

            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                return null;
            }
            $parsed = $format->parse($value);

            return $parsed?->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse a date value and return a human-readable preview.
     */
    public function parseDatePreviewHuman(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
    ): ?string {
        if ($value === '' || $formatValue === null) {
            return null;
        }

        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;

        try {
            if ($isDateTimeField) {
                $format = TimestampFormat::tryFrom($formatValue);
                if ($format === null) {
                    return null;
                }
                $parsed = $format->parse($value);

                return $parsed?->format('M j, Y g:i A');
            }

            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                return null;
            }
            $parsed = $format->parse($value);

            return $parsed?->format('M j, Y');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Validate a corrected value from a cache session.
     *
     * Convenience method that fetches analysis data from cache.
     *
     * @return array<string, mixed>|null Issue data array or null if valid
     */
    public function validateCorrectedValue(
        string $sessionId,
        string $csvColumn,
        string $newValue,
    ): ?array {
        if ($newValue === '') {
            return null;
        }

        $analysisData = $this->cache->getAnalysis($sessionId, $csvColumn);
        if ($analysisData === null) {
            return null;
        }

        $fieldType = $analysisData['fieldType'] ?? null;
        $formatValue = $analysisData['selectedDateFormat'] ?? $analysisData['detectedDateFormat'] ?? null;

        return $this->validateValue($newValue, $fieldType, $formatValue);
    }

    /**
     * Get the effective format value from analysis data.
     *
     * @param  array<string, mixed>  $analysisData
     */
    public function getEffectiveFormat(array $analysisData): ?string
    {
        return $analysisData['selectedDateFormat'] ?? $analysisData['detectedDateFormat'] ?? null;
    }

    /**
     * Check if a field type is a date or datetime field.
     */
    public function isDateField(?string $fieldType): bool
    {
        return $fieldType === FieldDataType::DATE->value
            || $fieldType === FieldDataType::DATE_TIME->value;
    }

    /**
     * Check if a field type is a datetime field (with time component).
     */
    public function isDateTimeField(?string $fieldType): bool
    {
        return $fieldType === FieldDataType::DATE_TIME->value;
    }

    /**
     * Internal validation logic.
     *
     * @return array{valid: bool, parsed: ?Carbon, issue: ?ValueIssue}
     */
    private function validateValueInternal(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
        int $rowCount = 1,
    ): array {
        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;
        $isDateField = $fieldType === FieldDataType::DATE->value || $isDateTimeField;

        if (! $isDateField || $formatValue === null) {
            return [
                'valid' => true,
                'parsed' => null,
                'issue' => null,
            ];
        }

        if ($isDateTimeField) {
            $format = TimestampFormat::tryFrom($formatValue);
            if ($format === null) {
                return [
                    'valid' => true,
                    'parsed' => null,
                    'issue' => null,
                ];
            }

            return $this->timestampValidator->validate($value, $format, $rowCount);
        }

        $format = DateFormat::tryFrom($formatValue);
        if ($format === null) {
            return [
                'valid' => true,
                'parsed' => null,
                'issue' => null,
            ];
        }

        return $this->dateValidator->validate($value, $format, $rowCount);
    }
}
