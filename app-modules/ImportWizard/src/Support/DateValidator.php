<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Carbon\Carbon;
use Relaticle\ImportWizard\Data\ValueIssue;
use Relaticle\ImportWizard\Enums\DateFormat;

/**
 * Validates date values against a specified format.
 */
final class DateValidator
{
    /**
     * Validate a date value against the specified format.
     *
     * @return array{valid: bool, parsed: ?Carbon, issue: ?ValueIssue, isAmbiguous: bool}
     */
    public function validate(string $value, DateFormat $format, int $rowCount = 1): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'valid' => true,
                'parsed' => null,
                'issue' => null,
                'isAmbiguous' => false,
            ];
        }

        // Check if it's ambiguous (could be parsed differently in other formats)
        $isAmbiguous = DateFormat::isAmbiguous($value);

        // Try to parse with the specified format
        $parsed = $format->parse($value);

        if (! $parsed instanceof \Carbon\Carbon) {
            // Cannot parse in specified format
            return [
                'valid' => false,
                'parsed' => null,
                'issue' => new ValueIssue(
                    value: $value,
                    message: $this->getInvalidMessage($value, $format),
                    rowCount: $rowCount,
                    severity: 'error',
                    issueType: 'invalid',
                ),
                'isAmbiguous' => false,
            ];
        }

        // Successfully parsed, but may be ambiguous
        if ($isAmbiguous) {
            // Show what both interpretations would be
            $alternativeFormat = $format === DateFormat::EUROPEAN
                ? DateFormat::AMERICAN
                : DateFormat::EUROPEAN;

            $alternativeParsed = $alternativeFormat->parse($value);

            // Only mark as ambiguous if the alternative also parses to a different date
            if ($alternativeParsed instanceof \Carbon\Carbon && ! $parsed->isSameDay($alternativeParsed)) {
                return [
                    'valid' => true,
                    'parsed' => $parsed,
                    'issue' => new ValueIssue(
                        value: $value,
                        message: $this->getAmbiguousMessage($value, $parsed, $alternativeParsed, $format, $alternativeFormat),
                        rowCount: $rowCount,
                        severity: 'warning',
                        issueType: 'ambiguous',
                    ),
                    'isAmbiguous' => true,
                ];
            }
        }

        // Valid and unambiguous
        return [
            'valid' => true,
            'parsed' => $parsed,
            'issue' => null,
            'isAmbiguous' => false,
        ];
    }

    /**
     * Validate multiple values and return issues.
     *
     * @param  array<string, int>  $uniqueValues  Map of value to occurrence count
     * @return array{issues: array<ValueIssue>, validCount: int, ambiguousCount: int, invalidCount: int}
     */
    public function validateColumn(array $uniqueValues, DateFormat $format): array
    {
        $issues = [];
        $validCount = 0;
        $ambiguousCount = 0;
        $invalidCount = 0;

        foreach ($uniqueValues as $value => $count) {
            $result = $this->validate((string) $value, $format, $count);

            if ($result['issue'] !== null) {
                $issues[] = $result['issue'];
            }

            if ($result['valid']) {
                $validCount += $count;

                if ($result['isAmbiguous']) {
                    $ambiguousCount += $count;
                }
            } else {
                $invalidCount += $count;
            }
        }

        return [
            'issues' => $issues,
            'validCount' => $validCount,
            'ambiguousCount' => $ambiguousCount,
            'invalidCount' => $invalidCount,
        ];
    }

    /**
     * Get a human-readable message for an invalid date.
     */
    private function getInvalidMessage(string $value, DateFormat $format): string
    {
        return match ($format) {
            DateFormat::ISO => "Cannot parse '{$value}' as ISO date (expected YYYY-MM-DD)",
            DateFormat::EUROPEAN => "Cannot parse '{$value}' as European date (expected DD/MM/YYYY)",
            DateFormat::AMERICAN => "Cannot parse '{$value}' as American date (expected MM/DD/YYYY)",
        };
    }

    /**
     * Get a human-readable message for an ambiguous date.
     */
    private function getAmbiguousMessage(
        string $value,
        Carbon $parsed,
        Carbon $alternativeParsed,
        DateFormat $format,
        DateFormat $alternativeFormat
    ): string {
        $parsedDisplay = $parsed->format('M j, Y');
        $alternativeDisplay = $alternativeParsed->format('M j, Y');

        return "Ambiguous: '{$value}' could be {$parsedDisplay} ({$format->getLabel()}) or {$alternativeDisplay} ({$alternativeFormat->getLabel()})";
    }

    /**
     * Format a parsed date for preview display.
     */
    public function formatForPreview(?Carbon $date): string
    {
        return blank($date) ? '' : $date->format('M j, Y');
    }
}
