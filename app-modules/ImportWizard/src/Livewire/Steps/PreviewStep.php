<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Store\ImportRow;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PreviewStep extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithImportStore;
    use WithPagination;

    public ?string $batchId = null;

    public ?string $matchResolutionBatchId = null;

    public bool $isCompleted = false;

    public string $activeTab = 'all';

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->syncCompletionState();
        $this->syncMatchResolutionState();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    #[Computed]
    public function createCount(): int
    {
        return $this->store()->query()->toCreate()->count();
    }

    #[Computed]
    public function updateCount(): int
    {
        return $this->store()->query()->toUpdate()->count();
    }

    #[Computed]
    public function skipCount(): int
    {
        return $this->store()->query()->toSkip()->count();
    }

    #[Computed]
    public function errorCount(): int
    {
        return $this->store()->query()
            ->whereNotNull('validation')
            ->where('validation', '!=', '{}')
            ->count();
    }

    #[Computed]
    public function isImporting(): bool
    {
        if ($this->isCompleted) {
            return false;
        }

        return $this->batchId !== null
            || $this->import()->status === ImportStatus::Importing;
    }

    #[Computed]
    public function progressPercent(): int
    {
        if ($this->isCompleted) {
            return 100;
        }

        $processed = $this->processedCount();
        $total = $this->totalRowCount();

        if ($processed === 0 || $total === 0) {
            return 0;
        }

        return (int) min(round(($processed / $total) * 100), 99);
    }

    #[Computed]
    public function processedCount(): int
    {
        return array_sum($this->results());
    }

    #[Computed]
    public function matchField(): ?string
    {
        $mappedFieldKeys = $this->columns()
            ->filter(fn (ColumnData $col): bool => $col->isFieldMapping())
            ->pluck('target')
            ->all();

        return $this->import()->getImporter()
            ->getMatchFieldForMappedColumns($mappedFieldKeys)
            ?->label;
    }

    #[Computed]
    public function totalRowCount(): int
    {
        return $this->store()->query()->count();
    }

    /** @return Collection<int, ColumnData> */
    #[Computed]
    public function columns(): Collection
    {
        return $this->import()->columnMappings();
    }

    /** @return LengthAwarePaginator<int, ImportRow> */
    #[Computed]
    public function previewRows(): LengthAwarePaginator
    {
        $query = $this->store()->query()->orderBy('row_number');

        if ($this->activeTab !== 'all') {
            $query->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(relationships) WHERE json_extract(value, '$.relationship') = ?)",
                [$this->activeTab]
            );
        }

        return $query->paginate(25);
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, count: int}>
     */
    #[Computed]
    public function relationshipTabs(): array
    {
        $connection = $this->store()->connection();

        return $this->columns()
            ->filter(fn (ColumnData $col): bool => $col->isEntityLinkMapping())
            ->map(function (ColumnData $col) use ($connection): array {
                $uniqueCount = $connection->selectOne(
                    "SELECT COUNT(*) as total FROM ({$this->uniqueRelationshipSubquery()}) sub",
                    [$col->entityLink],
                );

                return [
                    'key' => $col->entityLink,
                    'label' => $col->getLabel(),
                    'icon' => $col->getIcon(),
                    'count' => (int) $uniqueCount->total,
                ];
            })
            ->values()
            ->all();
    }

    /** @phpstan-ignore missingType.generics */
    #[Computed]
    public function relationshipSummary(): Paginator
    {
        if ($this->activeTab === 'all') {
            return new Paginator([], 0, 25);
        }

        $perPage = 25;
        $page = $this->getPage();
        $offset = ($page - 1) * $perPage;
        $connection = $this->store()->connection();
        $groupBy = $this->relationshipGroupByClause();

        $rows = $connection->select("
            SELECT
                json_extract(je.value, '$.action') as action,
                COALESCE(json_extract(je.value, '$.name'), 'Record #' || json_extract(je.value, '$.id')) as name,
                json_extract(je.value, '$.id') as id,
                COUNT(*) as count
            FROM import_rows, json_each(import_rows.relationships) AS je
            WHERE json_extract(je.value, '$.relationship') = ?
            GROUP BY action, {$groupBy}
            ORDER BY count DESC
            LIMIT ? OFFSET ?
        ", [$this->activeTab, $perPage, $offset]);

        $total = $connection->selectOne(
            "SELECT COUNT(*) as total FROM ({$this->uniqueRelationshipSubquery()}) sub",
            [$this->activeTab],
        );

        /** @var list<array{action: string, name: string, id: string|null, count: int}> $items */
        $items = array_map(fn (\stdClass $row): array => [
            'action' => $row->action === 'update' ? 'link' : 'create',
            'name' => $row->name,
            'id' => $row->id,
            'count' => (int) $row->count,
        ], $rows);

        return new Paginator(
            $items,
            $total->total,
            $perPage,
            $page,
        );
    }

    /** @return array{link: int, create: int} */
    #[Computed]
    public function relationshipStats(): array
    {
        if ($this->activeTab === 'all') {
            return ['link' => 0, 'create' => 0];
        }

        $rows = $this->store()->connection()->select("
            SELECT action, COUNT(*) as count FROM (
                SELECT json_extract(je.value, '$.action') as action
                FROM import_rows, json_each(import_rows.relationships) AS je
                WHERE json_extract(je.value, '$.relationship') = ?
                GROUP BY action, {$this->relationshipGroupByClause()}
            )
            GROUP BY action
        ", [$this->activeTab]);

        $stats = ['link' => 0, 'create' => 0];

        foreach ($rows as $row) {
            if ($row->action === 'update') {
                $stats['link'] = (int) $row->count;
            } else {
                $stats['create'] = (int) $row->count;
            }
        }

        return $stats;
    }

    /** @return array{created: int, updated: int, skipped: int, failed: int} */
    #[Computed]
    public function results(): array
    {
        $import = $this->import();

        return [
            'created' => $import->created_rows,
            'updated' => $import->updated_rows,
            'skipped' => $import->skipped_rows,
            'failed' => $import->failed_rows,
        ];
    }

    public function startImportAction(): Action
    {
        return Action::make('startImport')
            ->label($this->matchResolutionBatchId !== null ? 'Resolving matches...' : 'Start Import')
            ->color('primary')
            ->icon($this->matchResolutionBatchId !== null ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedPlay)
            ->disabled(fn (): bool => $this->matchResolutionBatchId !== null)
            ->requiresConfirmation()
            ->modalHeading('Start import')
            ->modalDescription('Are you sure that you want to start running this import?')
            ->modalSubmitActionLabel('Start import')
            ->action(fn () => $this->startImport());
    }

    public function downloadFailedRowsAction(): Action
    {
        return Action::make('downloadFailedRows')
            ->label('Download Failed Rows')
            ->color('danger')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => $this->isCompleted && $this->import()->failed_rows > 0)
            ->action(fn (): StreamedResponse => $this->downloadFailedRows());
    }

    public function downloadFailedRows(): StreamedResponse
    {
        $import = $this->import();
        $headers = $import->headers;

        return response()->streamDownload(function () use ($import, $headers): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [...$headers, 'Import Error'], escape: '\\');

            $import->failedRows()
                ->lazyById(100)
                ->each(function (FailedImportRow $row) use ($handle, $headers): void {
                    $values = [];

                    foreach ($headers as $header) {
                        $values[] = $row->data[$header] ?? '';
                    }

                    $values[] = $row->validation_error ?? '';
                    fputcsv($handle, $values, escape: '\\');
                });

            fclose($handle);
        }, 'failed-rows.csv', ['Content-Type' => 'text/csv']);
    }

    public function checkMatchResolution(): void
    {
        $this->syncMatchResolutionState();

        if ($this->matchResolutionBatchId === null) {
            $this->dispatch('match-resolution-complete');
        }
    }

    public function startImport(): void
    {
        if ($this->matchResolutionBatchId !== null || $this->batchId !== null || $this->isCompleted) {
            return;
        }

        if (! $this->import()->transitionToImporting()) {
            return;
        }

        $batch = Bus::batch([
            new ExecuteImportJob(
                importId: $this->import()->id,
                teamId: $this->import()->team_id,
            ),
        ])->dispatch();

        $this->batchId = $batch->id;
        $this->dispatch('import-polling-start');
        $this->dispatch('import-started')->to(ImportWizard::class);
    }

    public function checkImportProgress(): void
    {
        $this->refreshImport();
        $this->syncCompletionState();

        unset($this->results, $this->progressPercent, $this->processedCount, $this->isImporting);

        if ($this->isCompleted) {
            $this->dispatch('import-polling-complete');
        }
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.preview-step');
    }

    private function syncCompletionState(): void
    {
        $this->isCompleted = in_array($this->import()->status, [
            ImportStatus::Completed,
            ImportStatus::Failed,
        ], true);
    }

    private function syncMatchResolutionState(): void
    {
        if ($this->matchResolutionBatchId !== null) {
            $batch = Bus::findBatch($this->matchResolutionBatchId);

            if ($batch === null || $batch->finished()) {
                $this->matchResolutionBatchId = null;
            }

            return;
        }

        $batchId = Cache::get("import-{$this->storeId}-match-resolution-batch");

        if ($batchId === null) {
            return;
        }

        $batch = Bus::findBatch($batchId);

        $this->matchResolutionBatchId = ($batch !== null && ! $batch->finished())
            ? $batchId
            : null;
    }

    private function relationshipGroupByClause(): string
    {
        return "CASE
            WHEN json_extract(je.value, '$.action') = 'update' THEN json_extract(je.value, '$.id')
            ELSE json_extract(je.value, '$.name')
        END";
    }

    private function uniqueRelationshipSubquery(): string
    {
        return "SELECT 1
            FROM import_rows, json_each(import_rows.relationships) AS je
            WHERE json_extract(je.value, '$.relationship') = ?
            GROUP BY json_extract(je.value, '$.action'), {$this->relationshipGroupByClause()}";
    }
}
