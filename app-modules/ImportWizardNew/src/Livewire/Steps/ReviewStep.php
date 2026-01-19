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

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $columnAnalyses = [];

    public string $selectedColumn = '';

    public bool $analysisComplete = false;

    public int $valuesPage = 1;

    public int $perPage = 50;

    private ?BaseImporter $importer = null;

    private ?ColumnAnalyzer $analyzer = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->runAnalysis();
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
            $this->valuesPage = 1;
            unset($this->columnValues);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function columnValues(): array
    {
        if ($this->selectedColumn === '') {
            return [];
        }

        $limit = $this->valuesPage * $this->perPage;

        return $this->getAnalyzer()
            ->getUniqueValues($this->selectedColumn, $limit)
            ->all();
    }

    public function loadMore(): void
    {
        $this->valuesPage++;
        unset($this->columnValues);
    }

    public function skipValue(string $csvColumn, string $value): void
    {
        $this->getAnalyzer()->skipValue($csvColumn, $value);
        $this->refreshColumnAnalysis($csvColumn);
        unset($this->columnValues);
    }

    public function updateMappedValue(string $csvColumn, string $rawValue, string $newValue): void
    {
        $this->getAnalyzer()->applyCorrection($csvColumn, $rawValue, $newValue);
        $this->refreshColumnAnalysis($csvColumn);
        unset($this->columnValues);
    }

    public function continueToPreview(): void
    {
        $store = $this->store();
        if ($store instanceof ImportStore) {
            $store->setStatus(ImportStatus::Importing);
        }

        $this->dispatch('completed');
    }

    private function refreshColumnAnalysis(string $csvColumn): void
    {
        $mapping = $this->store()?->getMapping($csvColumn);
        if ($mapping === null) {
            return;
        }

        $result = $this->getAnalyzer()->analyzeColumn($mapping);
        $this->columnAnalyses[$csvColumn] = $result->toArray();
    }

    private function getAnalyzer(): ColumnAnalyzer
    {
        if (! $this->analyzer instanceof ColumnAnalyzer) {
            $store = $this->store();
            if (! $store instanceof ImportStore) {
                throw new \RuntimeException('ImportStore not available');
            }

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
