<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Enums\PendingActionStatus;

/**
 * @phpstan-type ResolvedAction array{operation: string, entity_type: string, status: string, label: string|null, record_id?: string|null, record_ids?: list<string>, records?: list<array{id: string, label: string|null, url: string}>, skipped?: list<string>, excluded?: list<array{record: string|null, fields: list<string>}>, failure?: string|null, just_decided?: bool}
 */
final readonly class ResolvedActionText
{
    /**
     * @param  list<ResolvedAction>  $justDecided
     */
    public static function resumeOpener(array $justDecided): string
    {
        if ($justDecided === []) {
            return 'The user decided the proposals above.';
        }

        $lines = ['The user decided the proposals above:'];

        foreach ($justDecided as $action) {
            $lines = [...$lines, ...self::lines([...$action, 'just_decided' => false], cite: false)];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  ResolvedAction  $action
     * @return list<string>
     */
    public static function lines(array $action, bool $cite): array
    {
        $records = $action['records'] ?? [];
        $skipped = $action['skipped'] ?? [];
        $excluded = $action['excluded'] ?? [];
        $failure = $action['failure'] ?? null;
        $marker = ($action['just_decided'] ?? false) ? 'JUST DECIDED, ' : '';
        $head = '- '.$marker.PendingActionStatus::from($action['status'])->promptWord().": {$action['operation']} ";
        $notWritten = 'NOT '.($action['operation'] === 'delete' ? 'deleted' : "{$action['operation']}d");

        if (count($records) > 1 || ($records !== [] && $skipped !== [])) {
            $lines = [$head.count($records)." {$action['entity_type']} records:"];

            foreach ($records as $record) {
                $lines[] = '    - '.self::recordText($record, $cite);
            }

            foreach ($skipped as $label) {
                $lines[] = "    - skipped by the user, {$notWritten}: ".self::quoted($label);
            }

            foreach ($excluded as $entry) {
                $lines[] = '    - fields unchecked by the user on '.self::quoted($entry['record']).', NOT written: '.implode(', ', $entry['fields']);
            }

            if (is_string($failure) && $failure !== '') {
                $lines[] = '    - an approval attempt failed before this decision: '.self::quoted($failure);
            }

            return $lines;
        }

        $line = $head."{$action['entity_type']} ".self::recordsText($action, $cite);

        if ($skipped !== []) {
            $line .= "; skipped by the user, {$notWritten}: ".implode(', ', array_map(self::quoted(...), $skipped));
        }

        foreach ($excluded as $entry) {
            $line .= '; fields unchecked by the user'
                .($entry['record'] === null ? '' : ' on '.self::quoted($entry['record']))
                .', NOT written: '.implode(', ', $entry['fields']);
        }

        if (is_string($failure) && $failure !== '') {
            $line .= '; an approval attempt failed before this decision: '.self::quoted($failure);
        }

        return [$line];
    }

    /**
     * @param  ResolvedAction  $action
     */
    private static function recordsText(array $action, bool $cite): string
    {
        $records = $action['records'] ?? [];

        if ($records !== []) {
            return implode(', ', array_map(
                static fn (array $record): string => self::recordText($record, $cite),
                $records,
            ));
        }

        $label = self::quoted($action['label']);

        if (! $cite || $action['status'] !== PendingActionStatus::Approved->value) {
            return $label;
        }

        $recordIds = $action['record_ids'] ?? [];
        $recordId = $action['record_id'] ?? null;

        if ($recordIds !== []) {
            return $label.' (ids: '.implode(',', $recordIds).')';
        }

        if (is_string($recordId) && $recordId !== '') {
            return "{$label} (id: {$recordId})";
        }

        return $label;
    }

    /**
     * @param  array{id: string, label: string|null, url: string}  $record
     */
    private static function recordText(array $record, bool $cite): string
    {
        $label = self::quoted($record['label']);

        return $cite ? "{$label} (id: {$record['id']}, url: {$record['url']})" : $label;
    }

    private static function quoted(?string $label): string
    {
        return $label !== null && $label !== ''
            ? '"'.PromptText::sanitize($label, 200).'"'
            : '(unnamed)';
    }
}
