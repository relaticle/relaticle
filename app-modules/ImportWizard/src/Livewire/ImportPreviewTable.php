<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Reactive;
use Livewire\Component;

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
        $progress = Cache::get("import:{$this->sessionId}:progress", [
            'processed' => 0,
            'creates' => 0,
            'updates' => 0,
        ]);

        $status = Cache::get("import:{$this->sessionId}:status", 'pending');

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
                'isProcessing' => $status === 'processing',
                'isReady' => $status === 'ready',
                'creates' => $progress['creates'],
                'updates' => $progress['updates'],
                'processed' => $progress['processed'],
                'rows' => $this->previewRows,
            ],
        ]);
    }
}
