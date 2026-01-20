<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizardNew\Data\ColumnAnalysisResult;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Enums\ImportStatus;
use Relaticle\ImportWizardNew\Importers\BaseImporter;
use Relaticle\ImportWizardNew\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizardNew\Store\ImportStore;
use Relaticle\ImportWizardNew\Support\ColumnAnalyzer;

/**
 * Step 3: Value review.
 *
 * Shows raw data → mapped value transformation.
 * Allows skipping unwanted values.
 */
final class ReviewStep extends Component
{
    use WithImportStore;

    /** @var list<string> */
    private const array ALLOWED_SORT_FIELDS = ['raw_value', 'count'];

    /** @var list<string> */
    private const array ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    /** @var list<string> */
    private const array ALLOWED_FILTERS = ['all', 'modified', 'skipped'];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $columnAnalyses = [];

    public string $selectedColumn = '';

    public bool $analysisComplete = false;

    public int $valuesPage = 1;

    public int $perPage = 100;

    public string $search = '';

    public string $filter = 'all';

    public string $sortField = 'count';

    public string $sortDirection = 'desc';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $loadedValues = [];

    public int $totalFiltered = 0;

    private ?BaseImporter $importer = null;

    private ?ColumnAnalyzer $analyzer = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->runAnalysis();
        $this->loadInitialValues();
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.review-step');
    }

    #[Computed]
    public function selectedColumnAnalysis(): ?ColumnAnalysisResult
    {
        if ($this->selectedColumn === '' || ! isset($this->columnAnalyses[$this->selectedColumn])) {
            return null;
        }

        return ColumnAnalysisResult::from($this->columnAnalyses[$this->selectedColumn]);
    }

    #[Computed]
    public function totalPages(): int
    {
        return $this->totalFiltered > 0 ? (int) ceil($this->totalFiltered / $this->perPage) : 1;
    }

    public function runAnalysis(): void
    {
        $analyzer = $this->getAnalyzer();
        $results = $analyzer->analyzeAllColumns();

        $this->columnAnalyses = $results
            ->map(fn (ColumnAnalysisResult $result): array => $result->toArray())
            ->all();

        $this->selectedColumn = $results->keys()->first() ?? '';
        $this->analysisComplete = true;
    }

    public function selectColumn(string $csvColumn): void
    {
        if (isset($this->columnAnalyses[$csvColumn])) {
            $this->selectedColumn = $csvColumn;
            $this->resetColumnState();
            $this->loadInitialValues();
        }
    }

    private function resetColumnState(): void
    {
        $this->loadedValues = [];
        $this->search = '';
        $this->filter = 'all';
        $this->sortField = 'count';
        $this->sortDirection = 'desc';
    }

    public function updatedSearch(): void
    {
        $this->valuesPage = 1;
        $this->loadPage();
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, self::ALLOWED_FILTERS, true)) {
            return;
        }

        $this->filter = $filter;
        $this->valuesPage = 1;
        $this->loadPage();
    }

    public function setSort(string $field, string $direction): void
    {
        if (! in_array($field, self::ALLOWED_SORT_FIELDS, true)) {
            return;
        }

        if (! in_array($direction, self::ALLOWED_SORT_DIRECTIONS, true)) {
            return;
        }

        $this->sortField = $field;
        $this->sortDirection = $direction;
        $this->valuesPage = 1;
        $this->loadPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
        $this->valuesPage = 1;
        $this->loadPage();
    }

    #[Computed]
    public function sortLabel(): string
    {
        return match ($this->sortField) {
            'raw_value' => 'Raw value',
            default => 'Row count',
        };
    }

    /**
     * @return array{all: int, modified: int, skipped: int}
     */
    #[Computed]
    public function filterCounts(): array
    {
        if ($this->selectedColumn === '') {
            return ['all' => 0, 'modified' => 0, 'skipped' => 0];
        }

        return $this->getAnalyzer()->getFilterCounts($this->selectedColumn, $this->search);
    }

    public function loadInitialValues(): void
    {
        if ($this->selectedColumn === '') {
            return;
        }

        $this->valuesPage = 1;
        $this->loadPage();
    }

    public function previousPage(): void
    {
        if ($this->valuesPage <= 1 || $this->selectedColumn === '') {
            return;
        }

        $this->valuesPage--;
        $this->loadPage();
    }

    public function nextPage(): void
    {
        if ($this->valuesPage >= $this->totalPages() || $this->selectedColumn === '') {
            return;
        }

        $this->valuesPage++;
        $this->loadPage();
    }

    private function loadPage(): void
    {
        $result = $this->getAnalyzer()->getUniqueValuesPaginated(
            $this->selectedColumn,
            page: $this->valuesPage,
            perPage: $this->perPage,
            search: $this->search,
            filter: $this->filter,
            sortField: $this->sortField,
            sortDirection: $this->sortDirection,
        );

        $this->loadedValues = $result['values']->all();
        $this->totalFiltered = $result['totalFiltered'];
    }

    public function skipValue(string $csvColumn, string $value): void
    {
        $this->getAnalyzer()->skipValue($csvColumn, $value);
        $this->refreshColumnAnalysis($csvColumn);

        // Skipped values: mapped='' (empty string)
        // Remove from view if on 'modified' filter (no longer modified)
        if ($this->filter === 'modified') {
            $this->removeValueFromList($value);
        } else {
            $this->updateValueInPlace($value, '');
        }
    }

    public function updateMappedValue(string $csvColumn, string $rawValue, string $newValue): void
    {
        $this->getAnalyzer()->applyCorrection($csvColumn, $rawValue, $newValue);
        $this->refreshColumnAnalysis($csvColumn);

        // When restoring to original (trimmed values match), correction was removed
        // so mapped should be null (no correction exists)
        $trimmedNew = trim($newValue);
        $isRestored = $trimmedNew === trim($rawValue);
        $mappedValue = $isRestored ? null : $trimmedNew;

        // Remove from view if action moves row out of current filter
        $shouldRemove = match ($this->filter) {
            'modified' => $isRestored,  // Restored values are no longer modified
            'skipped' => true,          // Any change moves it out of skipped
            default => false,           // 'all' filter: always keep in view
        };

        if ($shouldRemove) {
            $this->removeValueFromList($rawValue);
        } else {
            $this->updateValueInPlace($rawValue, $mappedValue);
        }
    }

    public function continueToPreview(): void
    {
        $store = $this->store();
        if ($store instanceof ImportStore) {
            $store->setStatus(ImportStatus::Importing);
        }

        $this->dispatch('completed');
    }

    private function updateValueInPlace(string $rawValue, ?string $newMapped): void
    {
        foreach ($this->loadedValues as $index => $value) {
            if ($value['raw'] === $rawValue) {
                $this->loadedValues[$index]['mapped'] = $newMapped;
                break;
            }
        }
    }

    private function removeValueFromList(string $rawValue): void
    {
        $this->loadedValues = array_values(
            array_filter(
                $this->loadedValues,
                fn (array $value): bool => $value['raw'] !== $rawValue
            )
        );
        $this->totalFiltered = max(0, $this->totalFiltered - 1);
    }

    private function refreshColumnAnalysis(string $csvColumn): void
    {
        $mapping = $this->store()?->getMapping($csvColumn);
        if (! $mapping instanceof \Relaticle\ImportWizardNew\Data\ColumnMapping) {
            return;
        }

        $result = $this->getAnalyzer()->analyzeColumn($mapping);
        $this->columnAnalyses[$csvColumn] = $result->toArray();
    }

    private function getAnalyzer(): ColumnAnalyzer
    {
        if (! $this->analyzer instanceof ColumnAnalyzer) {
            $store = $this->store();
            throw_unless($store instanceof ImportStore, \RuntimeException::class, 'ImportStore not available');

            $this->analyzer = new ColumnAnalyzer($store, $this->getImporter());
        }

        return $this->analyzer;
    }

    private function getImporter(): BaseImporter
    {
        if (! $this->importer instanceof BaseImporter) {
            $store = $this->store();
            $teamId = $store?->teamId() ?? (string) filament()->getTenant()?->getKey();
            $this->importer = $this->entityType->importer($teamId);
        }

        return $this->importer;
    }
}
