<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Carbon\Carbon;
use Relaticle\ImportWizard\Data\ValueIssue;
use Relaticle\ImportWizard\Enums\TimestampFormat;

/**
 * Validates timestamp (datetime) values against a specified format.
 */
final class TimestampValidator
{
    /**
     * Validate a timestamp value against the specified format.
     *
     * @return array{valid: bool, parsed: ?Carbon, issue: ?ValueIssue}
     */
    public function validate(string $value, TimestampFormat $format, int $rowCount = 1): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'valid' => true,
                'parsed' => null,
                'issue' => null,
            ];
        }

        // Try to parse with the specified format
        $parsed = $format->parse($value);

        if (! $parsed instanceof \Carbon\Carbon) {
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
            ];
        }

        return [
            'valid' => true,
            'parsed' => $parsed,
            'issue' => null,
        ];
    }

    /**
     * Validate multiple values and return issues.
     *
     * @param  array<string, int>  $uniqueValues  Map of value to occurrence count
     * @return array{issues: array<ValueIssue>, validCount: int, invalidCount: int}
     */
    public function validateColumn(array $uniqueValues, TimestampFormat $format): array
    {
        $issues = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($uniqueValues as $value => $count) {
            $result = $this->validate((string) $value, $format, $count);

            if ($result['issue'] !== null) {
                $issues[] = $result['issue'];
            }

            if ($result['valid']) {
                $validCount += $count;
            } else {
                $invalidCount += $count;
            }
        }

        return [
            'issues' => $issues,
            'validCount' => $validCount,
            'invalidCount' => $invalidCount,
        ];
    }

    /**
     * Get a human-readable message for an invalid timestamp.
     */
    private function getInvalidMessage(string $value, TimestampFormat $format): string
    {
        return match ($format) {
            TimestampFormat::ISO => "Cannot parse '{$value}' as ISO timestamp (expected YYYY-MM-DD HH:MM or YYYY-MM-DD HH:MM:SS)",
            TimestampFormat::EUROPEAN => "Cannot parse '{$value}' as European timestamp (expected HH:MM DD/MM/YYYY)",
            TimestampFormat::AMERICAN => "Cannot parse '{$value}' as American timestamp (expected HH:MM MM/DD/YYYY)",
        };
    }
}
