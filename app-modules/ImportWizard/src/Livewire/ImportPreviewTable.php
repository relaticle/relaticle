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

    /** @var array<int, array<string, mixed>> */
    #[Reactive]
    public array $previewRows;

    #[Reactive]
    public int $totalRows;

    public function render(): View
    {
        $data = ImportSessionData::find($this->sessionId);
        $status = $data?->status() ?? PreviewStatus::Pending;

        $columns = collect($this->columnMap)
            ->filter()
            ->map(fn ($_, $field): array => ['key' => $field, 'label' => str($field)->headline()->toString()])
            ->values()
            ->all();

        return view('import-wizard::livewire.import-preview-table', [
            'previewConfig' => [
                'sessionId' => $this->sessionId,
                'totalRows' => $this->totalRows,
                'columns' => $columns,
                'showCompanyMatch' => in_array($this->entityType, ['people', 'opportunities']),
                'isProcessing' => $status === PreviewStatus::Processing,
                'isReady' => $status === PreviewStatus::Ready,
                'creates' => $data !== null ? $data->creates : 0,
                'updates' => $data !== null ? $data->updates : 0,
                'processed' => $data !== null ? $data->processed : 0,
                'rows' => $this->previewRows,
            ],
        ]);
    }
}
