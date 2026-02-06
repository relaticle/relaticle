<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
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
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Store\ImportRow;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;
use Relaticle\ImportWizard\Support\Validation\ValidationError;

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

    /** @var Collection<int, ColumnData> */
    public Collection $columns;

    public ColumnData $selectedColumn;

    /** @var array<string, string> Column source => batch ID */
    public array $batchIds = [];

    private function connection(): Connection
    {
        return $this->store()->connection();
    }

    /**
     * Validate a value based on column type.
     *
     * - Entity links: Use EntityLinkValidator to check if target record exists
     * - Date corrections: Validate against ISO format (date picker output)
     * - Raw dates: Validate against user's selected format
     * - Other fields: Use FieldHandlerFactory
     *
     * @param  bool  $isCorrection  Whether this is a UI correction (true) or raw CSV value (false)
     */
    private function validateValue(ColumnData $column, string $value, bool $isCorrection = false): ?string
    {
        if ($column->isEntityLinkMapping()) {
            return $this->validateEntityLinkValue($column, $value);
        }

        if ($isCorrection && $column->getType()->isDateOrDateTime()) {
            $parsed = DateFormat::ISO->parse($value, $column->getType()->isTimestamp());

            return $parsed === null ? 'Invalid date format' : null;
        }

        $validator = new ColumnValidator;
        $error = $validator->validate($column, $value);

        return $error?->toStorageFormat();
    }

    /**
     * Validate an entity link value by checking if the target record exists.
     */
    private function validateEntityLinkValue(ColumnData $column, string $value): ?string
    {
        $validator = new EntityLinkValidator($this->store()->teamId());

        return $validator->validateFromColumn($column, $this->store()->getImporter(), $value);
    }

    /**
     * Update validation for all rows matching a raw value.
     * Sets error if provided, removes validation entry if null.
     */
    private function updateValidationForRawValue(string $jsonPath, string $rawValue, ?string $error): void
    {
        if ($error !== null) {
            $this->connection()->statement("
                UPDATE import_rows
                SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
                WHERE json_extract(raw_data, ?) = ?
            ", [$jsonPath, $error, $jsonPath, $rawValue]);
        } else {
            $this->connection()->statement('
                UPDATE import_rows
                SET validation = json_remove(validation, ?)
                WHERE json_extract(raw_data, ?) = ?
            ', [$jsonPath, $jsonPath, $rawValue]);
        }
    }

    /**
     * Dispatch a background job to validate all unique values for a column.
     * Returns the batch ID for progress tracking.
     */
    private function validateColumnAsync(ColumnData $column): string
    {
        $batch = Bus::batch([
            new ValidateColumnJob($this->store()->id(), $column, $this->store()->teamId()),
        ])
            ->name("Validate {$column->source}")
            ->dispatch();

        return $batch->id;
    }

    public function mount(): void
    {
        $this->columns = $this->store()->columnMappings();
        $this->selectedColumn = $this->columns->first();
        $this->filter = ReviewFilter::All;

        // Async validate ALL mapped columns (both field and entity link mappings)
        foreach ($this->columns as $column) {
            $this->batchIds[$column->source] = $this->validateColumnAsync($column);
        }
    }

    /**
     * Re-hydrate transient fields after Livewire deserialization.
     *
     * ColumnData's importField and entityLinkField are transient (not stored in JSON),
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
     * @return LengthAwarePaginator<int, ImportRow>
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
        return ImportRow::countUniqueValuesByFilter(
            $this->store()->query(),
            $this->selectedColumn->source
        );
    }

    public function selectColumn(string $columnSource): void
    {
        $this->selectedColumn = $this->columns->firstWhere('source', $columnSource);
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
        if (! $this->selectedColumn->isMultiChoicePredefined()) {
            return [];
        }

        return $this->selectedColumn->importField->options ?? [];
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

        // Update local state directly to avoid re-fetching
        $this->columns = $this->columns->map(
            fn (ColumnData $col): ColumnData => $col->source === $updated->source ? $updated : $col
        );
        $this->selectedColumn = $updated;

        // Dispatch async validation (hash includes format, so changed format = new cache keys)
        $this->batchIds[$this->selectedColumn->source] = $this->validateColumnAsync($this->selectedColumn);
    }

    /**
     * Update a mapped value (correction) for all rows with matching raw value.
     * Returns per-value validation errors for multi-value fields (consumed by Alpine via DOM event).
     *
     * @return array<string, string>
     */
    public function updateMappedValue(string $rawValue, string $newValue): array
    {
        if (blank($newValue)) {
            $this->skipValue($rawValue);

            return [];
        }

        $error = $this->validateValue($this->selectedColumn, $newValue, isCorrection: true);
        $jsonPath = '$.'.$this->selectedColumn->source;

        $this->connection()->statement("
            UPDATE import_rows
            SET corrections = json_set(COALESCE(corrections, '{}'), ?, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $newValue, $jsonPath, $rawValue]);

        $this->updateValidationForRawValue($jsonPath, $rawValue, $error);

        unset($this->columnErrorStatuses);

        if ($error === null || ! $this->selectedColumn->isMultiChoiceArbitrary()) {
            return [];
        }

        $validationError = ValidationError::fromStorageFormat($error);

        return $validationError?->getItemErrors() ?? [];
    }

    /**
     * Undo a correction and revert to the original raw value.
     * Re-validates the original value.
     */
    public function undoCorrection(string $rawValue): void
    {
        $error = $this->validateValue($this->selectedColumn, $rawValue, isCorrection: false);
        $jsonPath = '$.'.$this->selectedColumn->source;

        $this->connection()->statement('
            UPDATE import_rows
            SET corrections = json_remove(corrections, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        $this->updateValidationForRawValue($jsonPath, $rawValue, $error);

        unset($this->columnErrorStatuses);
    }

    /**
     * Skip a value (set to null during import).
     *
     * Also removes any corrections to ensure unskipping returns to the original
     * raw value with original validation errors (clean slate behavior).
     */
    public function skipValue(string $rawValue): void
    {
        $jsonPath = '$.'.$this->selectedColumn->source;

        $error = $this->validateValue($this->selectedColumn, $rawValue, isCorrection: false);

        $this->connection()->statement("
            UPDATE import_rows
            SET skipped = json_set(COALESCE(skipped, '{}'), ?, json('true')),
                corrections = json_remove(corrections, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $jsonPath, $jsonPath, $rawValue]);

        $this->updateValidationForRawValue($jsonPath, $rawValue, $error);

        unset($this->columnErrorStatuses);
    }

    /**
     * Unskip a value (restore to previous state with validation preserved).
     */
    public function unskipValue(string $rawValue): void
    {
        $jsonPath = '$.'.$this->selectedColumn->source;

        $this->connection()->statement('
            UPDATE import_rows
            SET skipped = json_remove(skipped, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        unset($this->columnErrorStatuses);
    }

    /**
     * Check validation progress and update UI when complete.
     */
    public function checkProgress(): void
    {
        $hasCompleted = false;

        foreach ($this->batchIds as $columnSource => $batchId) {
            if (Bus::findBatch($batchId)?->finished()) {
                unset($this->batchIds[$columnSource]);
                $this->dispatch('validation-complete', column: $columnSource);
                $hasCompleted = true;
            }
        }

        if ($hasCompleted) {
            unset($this->columnErrorStatuses);
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
     * Get error status for all columns in a single batched query.
     *
     * Persisted across requests to avoid recalculating on every hydration.
     * Cache is cleared when validations complete via checkProgress().
     *
     * @return array<string, bool>
     */
    #[Computed(persist: true, seconds: 60)]
    public function columnErrorStatuses(): array
    {
        $columnSources = $this->columns->pluck('source')->all();

        return ImportRow::getColumnErrorStatuses($this->store()->query(), $columnSources);
    }
}
