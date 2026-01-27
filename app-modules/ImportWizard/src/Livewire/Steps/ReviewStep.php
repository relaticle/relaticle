<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Enums\ReviewFilter;
use Relaticle\ImportWizard\Enums\SortDirection;
use Relaticle\ImportWizard\Enums\SortField;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Support\ImportValueValidator;

/**
 * Step 3: Value review.
 *
 * Shows raw data → mapped value transformation.
 * Allows skipping unwanted values.
 */
final class ReviewStep extends Component
{
    use WithImportStore;
    use WithPagination;

    public string $search = '';

    public ReviewFilter $filter = ReviewFilter::All;

    public SortField $sortField = SortField::Count;

    public SortDirection $sortDirection = SortDirection::Desc;

    public Collection $columns;

    public ColumnData $selectedColumn;

    /** @var array<string, string> Column source => batch ID */
    public array $batchIds = [];

    public function mount(): void
    {
        $this->columns = $this->store()->columnMappings();
        $columnSource = $this->store()->columnMappings()->first()->source;
        $this->selectColumn($columnSource);

        // Preload choice options to avoid N+1 queries
        $validator = new ImportValueValidator($this->store()->entityType()->value);
        $validator->preloadChoiceOptions($this->columns);

        // Async validate ALL mapped columns
        foreach ($this->columns as $column) {
            if (! $column->isFieldMapping()) {
                continue;
            }

            $this->batchIds[$column->source] = $this->store()->validateColumnAsync($column);
        }
    }

    /**
     * Re-hydrate transient fields after Livewire deserialization.
     *
     * ColumnData's importField and relationshipField are transient (not stored in JSON),
     * so we need to re-hydrate them on each request.
     */
    public function hydrate(): void
    {
        $this->columns = $this->store()->columnMappings();
        $this->selectedColumn = $this->store()->getColumnMapping($this->selectedColumn->source);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.review-step');
    }

    /**
     * Get unique values for the selected column with pagination.
     *
     * @return LengthAwarePaginator<object>
     */
    #[Computed]
    public function selectedColumnRows(): LengthAwarePaginator
    {
        $column = $this->selectedColumn->source;

        return $this->store()->query()
            ->uniqueValuesFor($column)
            ->forFilter($this->filter, $column)
            ->when(filled($this->search), fn ($q) => $q->searchValue($column, $this->search))
            ->orderBy($this->sortField->value, $this->sortDirection->value)
            ->paginate(100);
    }

    /**
     * Get counts for each filter option in a single query.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function filterCounts(): array
    {
        return $this->store()->countUniqueValuesByFilter($this->selectedColumn->source);
    }

    public function selectColumn(string $columnSource): void
    {
        $this->selectedColumn = $this->store()->getColumnMapping($columnSource);
        $this->setFilter(ReviewFilter::All->value);
    }

    /**
     * Get choice options for the selected column.
     *
     * @return array<int, array{label: string, value: string}>
     */
    #[Computed]
    public function choiceOptions(): array
    {
        return $this->store()->getChoiceOptions($this->selectedColumn);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = ReviewFilter::from($filter);
        $this->resetPage();
    }

    public function setSortField(string $field): void
    {
        $this->sortField = SortField::from($field);
        $this->resetPage();
    }

    public function setSortDirection(string $direction): void
    {
        $this->sortDirection = SortDirection::from($direction);
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = ReviewFilter::All;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Update the format setting for the selected column.
     *
     * @throws \InvalidArgumentException
     */
    public function setColumnFormat(string $type, string $value): void
    {
        $updated = match ($type) {
            'date' => $this->selectedColumn->withDateFormat(DateFormat::from($value)),
            'number' => $this->selectedColumn->withNumberFormat(NumberFormat::from($value)),
            default => throw new \InvalidArgumentException("Unknown format type: {$type}"),
        };

        $this->store()->updateColumnMapping($this->selectedColumn->source, $updated);
        $this->selectedColumn = $this->store()->getColumnMapping($this->selectedColumn->source);

        // Dispatch async validation (hash includes format, so changed format = new cache keys)
        $this->batchIds[$this->selectedColumn->source] = $this->store()->validateColumnAsync($this->selectedColumn);
    }

    /**
     * Update a mapped value (correction) for all rows with matching raw value.
     */
    public function updateMappedValue(string $rawValue, string $newValue): void
    {
        $this->store()->setCorrection($this->selectedColumn->source, $rawValue, $newValue);
    }

    /**
     * Undo a correction and revert to the original raw value.
     */
    public function undoCorrection(string $rawValue): void
    {
        $this->store()->clearCorrection($this->selectedColumn->source, $rawValue);
    }

    /**
     * Skip a value (set to null during import).
     */
    public function skipValue(string $rawValue): void
    {
        $this->store()->setValueSkipped($this->selectedColumn->source, $rawValue);
    }

    /**
     * Unskip a value (restore original value and re-validate).
     */
    public function unskipValue(string $rawValue): void
    {
        $this->store()->clearSkipped($this->selectedColumn->source, $rawValue);
    }

    /**
     * Check validation progress and update UI when complete.
     */
    public function checkProgress(): void
    {
        if (empty($this->batchIds)) {
            return;
        }

        foreach ($this->batchIds as $columnSource => $batchId) {
            $batch = Bus::findBatch($batchId);

            if ($batch?->finished()) {
                unset($this->batchIds[$columnSource]);
                $this->dispatch('validation-complete', column: $columnSource);
            }
        }
    }

    /**
     * Check if the selected column is currently being validated.
     */
    #[Computed]
    public function isSelectedColumnValidating(): bool
    {
        return isset($this->batchIds[$this->selectedColumn->source]);
    }

    /**
     * Check if a specific column has validation errors.
     */
    public function columnHasErrors(string $columnSource): bool
    {
        return $this->store()->query()
            ->withErrors($columnSource)
            ->exists();
    }
}
