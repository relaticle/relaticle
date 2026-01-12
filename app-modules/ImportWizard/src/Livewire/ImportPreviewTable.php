<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Relaticle\ImportWizard\Data\ImportSessionData;
use Relaticle\ImportWizard\Enums\PreviewStatus;

/**
 * Isolated Livewire component for the import preview table.
 *
 * This component is isolated from the parent ImportWizard to prevent
 * Livewire's morphing from breaking Alpine's reactive state.
 */
final class ImportPreviewTable extends Component
{
    #[Reactive]
    public string $sessionId;

    #[Reactive]
    public string $entityType;

    /** @var array<string, string> */
    #[Reactive]
    public array $columnMap;

    /** @var array<string, string> */
    #[Reactive]
    public array $fieldLabels = [];

    /** @var array<int, array<string, mixed>> */
    #[Reactive]
    public array $previewRows;

    #[Reactive]
    public int $totalRows;

    /** @var array<string, array{csvColumn: string, matcher: string}> */
    #[Reactive]
    public array $relationshipMappings = [];

    /** @var array<string, array{label: string, icon: string}> */
    #[Reactive]
    public array $relationshipFieldsMeta = [];

    public function render(): View
    {
        /** @var ImportSessionData|null */
        $data = ImportSessionData::find($this->sessionId);
        /** @var PreviewStatus */
        $status = $data?->status() ?? PreviewStatus::Pending;

        /** @var array<int, array{key: string, label: string}> */
        $columns = collect($this->columnMap)
            ->filter()
            // Exclude relationship columns - they get their own dedicated columns
            ->reject(fn (string $_, string $field): bool => str_starts_with($field, 'rel_'))
            ->map(fn (string $_, string $field): array => [
                'key' => $field,
                'label' => $this->fieldLabels[$field] ?? str($field)->headline()->toString(),
            ])
            ->values()
            ->all();

        // Build individual relationship columns from mapped relationships
        /** @var array<int, array{key: string, label: string, icon: string}> */
        $relationshipColumns = collect($this->relationshipMappings)
            ->filter(fn (array $mapping): bool => $mapping['csvColumn'] !== '')
            ->map(fn (array $mapping, string $relName): array => [
                'key' => $relName,
                'label' => $this->relationshipFieldsMeta[$relName]['label'] ?? str($relName)->headline()->toString(),
                'icon' => $this->relationshipFieldsMeta[$relName]['icon'] ?? 'heroicon-o-link',
            ])
            ->values()
            ->all();

        return view('import-wizard::livewire.import-preview-table', [
            'previewConfig' => [
                'sessionId' => $this->sessionId,
                'totalRows' => $this->totalRows,
                'columns' => $columns,
                'relationshipColumns' => $relationshipColumns,
                'isProcessing' => $status === PreviewStatus::Processing,
                'isReady' => $status === PreviewStatus::Ready,
                'creates' => $data instanceof ImportSessionData ? $data->creates : 0,
                'updates' => $data instanceof ImportSessionData ? $data->updates : 0,
                'newRelationships' => $data instanceof ImportSessionData ? $data->newRelationships : [],
                'processed' => $data instanceof ImportSessionData ? $data->processed : 0,
                'rows' => $this->previewRows,
            ],
        ]);
    }
}
