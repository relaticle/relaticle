<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

trait ReportsSkippedRecords
{
    /**
     * @param  array<string, mixed>  $record
     * @return array{record: string, reason: string}
     */
    protected function skippedRecord(array $record, int $index, string $reason): array
    {
        $label = null;

        foreach (['name', 'title', 'subject', 'email'] as $key) {
            if (is_string($record[$key] ?? null) && trim($record[$key]) !== '') {
                $label = trim($record[$key]);

                break;
            }
        }

        return [
            'record' => $label ?? 'record '.($index + 1),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{record: string, reason: string}>  $skipped
     */
    protected function everyRecordFailedError(array $skipped): string
    {
        $reasons = implode(' ', array_map(
            static fn (array $skip): string => "{$skip['record']}: ".rtrim($skip['reason'], '.').'.',
            $skipped,
        ));

        return (string) json_encode([
            'error' => "No proposal was created; every record failed validation. {$reasons}"
                .' Tell the user each reason. Do not retry with the same values.',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<array{record: string, reason: string}>  $skipped
     * @return array<string, mixed>
     */
    protected function withSkippedRecords(array $envelope, array $skipped): array
    {
        if ($skipped === []) {
            return $envelope;
        }

        $envelope['skipped_records'] = $skipped;
        $envelope['skipped_note'] = 'These records failed validation and are NOT part of the proposal.'
            .' Tell the user each skipped record and its reason.';

        return $envelope;
    }
}
