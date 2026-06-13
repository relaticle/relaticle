<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\User;
use Relaticle\Chat\Support\TeamMembersContext;

/**
 * Rebuilds a proposal item's display_data from a clean action_data record.
 *
 * Produces core rows (name/title + optional Account Owner for company),
 * carries forward read-only core rows the builder doesn't own from
 * existingFields, then appends custom-field rows via CustomFieldsDisplayFormatter.
 */
final readonly class ProposalDisplayBuilder
{
    /**
     * Per-entity title/summary literals — must match each Create*Tool::buildRecordDisplay()
     * exactly so the card heading is stable across an edit.
     *
     * @var array<string, array{title: string, nameKey: string, label: string, summaryPrefix: string}>
     */
    private const array ENTITY_MAP = [
        'company' => ['title' => 'Create Company', 'nameKey' => 'name', 'label' => 'Name', 'summaryPrefix' => 'Create company'],
        'people' => ['title' => 'Create Person', 'nameKey' => 'name', 'label' => 'Name', 'summaryPrefix' => 'Create person'],
        'opportunity' => ['title' => 'Create Opportunity', 'nameKey' => 'name', 'label' => 'Name', 'summaryPrefix' => 'Create opportunity'],
        'task' => ['title' => 'Create Task', 'nameKey' => 'title', 'label' => 'Title', 'summaryPrefix' => 'Create task'],
        'note' => ['title' => 'Create Note', 'nameKey' => 'title', 'label' => 'Title', 'summaryPrefix' => 'Create note'],
    ];

    public function __construct(
        private CustomFieldsDisplayFormatter $formatter,
    ) {}

    /**
     * @param  array<string, mixed>  $record  clean action_data (core keys + optional custom_fields map)
     * @param  list<array<string, mixed>>  $existingFields  current display fields, to carry forward read-only core rows
     * @return array{title: string, summary: string, fields: list<array<string, mixed>>}
     */
    public function build(User $user, string $entityType, array $record, array $existingFields): array
    {
        $meta = self::ENTITY_MAP[$entityType] ?? [
            'title' => 'Create '.ucfirst($entityType),
            'nameKey' => 'name',
            'label' => 'Name',
            'summaryPrefix' => 'Create '.lcfirst($entityType),
        ];

        $nameValue = (string) ($record[$meta['nameKey']] ?? '');
        $title = $meta['title'];
        $summary = "{$meta['summaryPrefix']} \"{$nameValue}\"";

        $coreRows = $this->buildCoreRows($meta, $nameValue, $entityType, $record);
        $ownedLabels = array_map(fn (array $row): string => $row['label'], $coreRows);

        $carried = $this->carryForwardRows($existingFields, $ownedLabels);

        $customFields = is_array($record['custom_fields'] ?? null) ? $record['custom_fields'] : [];
        /** @var array<string, mixed> $customFields */
        $customRows = $this->formatter->format($user, $entityType, $customFields, null);

        return [
            'title' => $title,
            'summary' => $summary,
            'fields' => array_merge($coreRows, $carried, $customRows),
        ];
    }

    /**
     * @param  array{title: string, nameKey: string, label: string, summaryPrefix: string}  $meta
     * @param  array<string, mixed>  $record
     * @return list<array{label: string, code: string, value: string}>
     */
    private function buildCoreRows(array $meta, string $nameValue, string $entityType, array $record): array
    {
        $rows = [
            ['label' => $meta['label'], 'code' => $meta['nameKey'], 'value' => $nameValue],
        ];

        if ($entityType === 'company') {
            $ownerId = $record['account_owner_id'] ?? null;

            if (is_string($ownerId) && $ownerId !== '') {
                $rows[] = [
                    'label' => 'Account Owner',
                    'code' => 'account_owner_id',
                    'value' => TeamMembersContext::nameOf($ownerId) ?? $ownerId,
                ];
            }
        }

        return $rows;
    }

    /**
     * Keep rows from existingFields that:
     * - have a 'label' key
     * - whose label is not already produced by the builder (not in $ownedLabels)
     * - do NOT have a 'type' key (custom-field rows carry type; they're re-derived fresh)
     *
     * @param  list<array<string, mixed>>  $existingFields
     * @param  list<string>  $ownedLabels
     * @return list<array<string, mixed>>
     */
    private function carryForwardRows(array $existingFields, array $ownedLabels): array
    {
        $carried = [];

        foreach ($existingFields as $row) {
            if (! isset($row['label'])) {
                continue;
            }

            if (in_array($row['label'], $ownedLabels, true)) {
                continue;
            }

            if (array_key_exists('type', $row)) {
                continue;
            }

            $carried[] = $row;
        }

        return $carried;
    }
}
