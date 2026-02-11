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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Store\ImportRow;
use Relaticle\ImportWizard\Support\MatchResolver;

final class PreviewStep extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithImportStore;
    use WithPagination;

    public ?string $batchId = null;

    public bool $isCompleted = false;

    public string $activeTab = 'all';

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->resolveMatches();
        $this->syncCompletionState();
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
        return $this->batchId !== null && ! $this->isCompleted;
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
        $results = $this->results();

        if ($results === null) {
            return 0;
        }

        return array_sum($results);
    }

    #[Computed]
    public function matchField(): ?string
    {
        $mappedFieldKeys = $this->columns()
            ->filter(fn (ColumnData $col): bool => $col->isFieldMapping())
            ->pluck('target')
            ->all();

        return $this->store()->getImporter()
            ->getMatchFieldForMappedColumns($mappedFieldKeys)
            ?->label;
    }

    #[Computed]
    public function totalRowCount(): int
    {
        return $this->store()->query()->count();
    }

    /**
     * @return Collection<int, ColumnData>
     */
    #[Computed]
    public function columns(): Collection
    {
        return $this->store()->columnMappings();
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
        return $this->columns()
            ->filter(fn (ColumnData $col): bool => $col->isEntityLinkMapping())
            ->map(fn (ColumnData $col): array => [
                'key' => $col->entityLink,
                'label' => $col->getLabel(),
                'icon' => $col->getIcon(),
                'count' => $this->store()->query()->whereRaw(
                    "EXISTS (SELECT 1 FROM json_each(relationships) WHERE json_extract(value, '$.relationship') = ?)",
                    [$col->entityLink]
                )->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{action: string, name: string, id: string|null, count: int}>
     */
    #[Computed]
    public function relationshipSummary(): array
    {
        if ($this->activeTab === 'all') {
            return [];
        }

        $rows = $this->store()->query()
            ->whereNotNull('relationships')
            ->where('relationships', '!=', '[]')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            foreach ($row->relationships ?? [] as $match) {
                if ($match->relationship !== $this->activeTab) {
                    continue;
                }

                $groupKey = $match->isExisting()
                    ? "link:{$match->id}"
                    : "create:{$match->name}";

                $summary[$groupKey] ??= [
                    'action' => $match->isExisting() ? 'link' : 'create',
                    'name' => $match->name ?? "Record #{$match->id}",
                    'id' => $match->id,
                    'count' => 0,
                ];

                $summary[$groupKey]['count']++;
            }
        }

        return collect($summary)->sortByDesc('count')->values()->all();
    }

    /**
     * @return array{link: int, create: int}
     */
    #[Computed]
    public function relationshipStats(): array
    {
        $summary = collect($this->relationshipSummary());

        return [
            'link' => $summary->where('action', 'link')->sum('count'),
            'create' => $summary->where('action', 'create')->sum('count'),
        ];
    }

    /**
     * @return array<string, int>|null
     */
    #[Computed]
    public function results(): ?array
    {
        return $this->store()->results();
    }

    public function startImportAction(): Action
    {
        return Action::make('startImport')
            ->label('Start Import')
            ->color('primary')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalHeading('Start import')
            ->modalDescription('Are you sure that you want to start running this import?')
            ->modalSubmitActionLabel('Start import')
            ->action(fn () => $this->startImport());
    }

    public function startImport(): void
    {
        $store = $this->store();
        $store->setStatus(ImportStatus::Importing);

        $batch = Bus::batch([
            new ExecuteImportJob(
                importId: $store->id(),
                teamId: $store->teamId(),
            ),
        ])->dispatch();

        $this->batchId = $batch->id;
    }

    public function checkImportProgress(): void
    {
        if ($this->batchId === null) {
            return;
        }

        $this->store()->refreshMeta();
        $this->syncCompletionState();

        unset($this->results, $this->progressPercent, $this->processedCount);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.preview-step');
    }

    private function resolveMatches(): void
    {
        $store = $this->store();
        (new MatchResolver($store, $store->getImporter()))->resolve();
    }

    private function syncCompletionState(): void
    {
        $status = $this->store()->status();

        $this->isCompleted = $status === ImportStatus::Completed
            || $status === ImportStatus::Failed;
    }
}
