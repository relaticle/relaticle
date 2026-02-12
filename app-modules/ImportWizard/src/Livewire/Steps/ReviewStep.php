<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Enums\ReviewFilter;
use Relaticle\ImportWizard\Enums\SortDirection;
use Relaticle\ImportWizard\Enums\SortField;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Store\ImportRow;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;
use Relaticle\ImportWizard\Support\Validation\ValidationError;

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

    private function selectedColumnJsonPath(): string
    {
        return '$.'.$this->selectedColumn->source;
    }

    private function validateValue(ColumnData $column, string $value, bool $isCorrection = false): ?string
    {
        if ($column->isEntityLinkMapping()) {
            return $this->validateEntityLinkValue($column, $value);
        }

        if ($isCorrection && $column->getType()->isDateOrDateTime()) {
            return DateFormat::ISO->parse($value, $column->getType()->isTimestamp()) instanceof \Carbon\Carbon
                ? null
                : 'Invalid date format';
        }

        return (new ColumnValidator)->validate($column, $value)?->toStorageFormat();
    }

    private function validateEntityLinkValue(ColumnData $column, string $value): ?string
    {
        $store = $this->store();
        $validator = new EntityLinkValidator($store->teamId());

        return $validator->validateFromColumn($column, $store->getImporter(), $value);
    }

    private function updateValidationForRawValue(string $jsonPath, string $rawValue, ?string $error): void
    {
        if ($error === null) {
            $this->connection()->statement('
                UPDATE import_rows
                SET validation = json_remove(validation, ?)
                WHERE json_extract(raw_data, ?) = ?
            ', [$jsonPath, $jsonPath, $rawValue]);

            return;
        }

        $this->connection()->statement("
            UPDATE import_rows
            SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $error, $jsonPath, $rawValue]);
    }

    private function validateColumnAsync(ColumnData $column): string
    {
        $batch = Bus::batch([
            new ValidateColumnJob($this->store()->id(), $column, $this->store()->teamId()),
        ])
            ->name("Validate {$column->source}")
            ->dispatch();

        return $batch->id;
    }

    private function clearRelationshipsForReentry(): void
    {
        $this->connection()->statement('UPDATE import_rows SET relationships = NULL');
    }

    private function dispatchMatchResolution(): string
    {
        $store = $this->store();

        $batch = Bus::batch([
            new ResolveMatchesJob(
                importId: $store->id(),
                teamId: $store->teamId(),
            ),
        ])
            ->name('Match resolution')
            ->dispatch();

        return $batch->id;
    }

    public function mount(): void
    {
        $this->columns = $this->store()->columnMappings();
        $this->selectedColumn = $this->columns->first();

        if (! $this->hasMappingsChanged()) {
            return;
        }

        $this->clearRelationshipsForReentry();

        foreach ($this->columns as $column) {
            $this->batchIds[$column->source] = $this->validateColumnAsync($column);
        }

        $this->batchIds['__match_resolution'] = $this->dispatchMatchResolution();

        $this->store()->updateMeta([
            'mappings_hash' => $this->currentMappingsHash(),
        ]);
    }

    public function hydrate(): void
    {
        $this->columns = $this->store()->columnMappings();
        $this->selectedColumn = $this->store()->getColumnMapping($this->selectedColumn->source);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.review-step');
    }

    /** @return LengthAwarePaginator<int, ImportRow> */
    #[Computed]
    public function selectedColumnRows(): LengthAwarePaginator
    {
        $column = $this->selectedColumn->source;

        return $this->store()->query()
            ->uniqueValuesFor($column)
            ->forFilter($this->filter, $column)
            ->when(filled($this->search), fn (Builder $q) => $q->searchValue($column, $this->search))
            ->orderBy($this->sortField->value, $this->sortDirection->value)
            ->paginate(100);
    }

    /** @return array<string, int> */
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

    /** @return array<int, array{label: string, value: string}> */
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

    public function setColumnFormat(string $type, string $value): void
    {
        $updated = match ($type) {
            'date' => $this->selectedColumn->withDateFormat(DateFormat::from($value)),
            'number' => $this->selectedColumn->withNumberFormat(NumberFormat::from($value)),
            default => throw new \InvalidArgumentException("Unknown format type: {$type}"),
        };

        $this->store()->updateColumnMapping($this->selectedColumn->source, $updated);

        $this->columns = $this->columns->map(
            fn (ColumnData $col): ColumnData => $col->source === $updated->source ? $updated : $col
        );
        $this->selectedColumn = $updated;

        $this->batchIds[$this->selectedColumn->source] = $this->validateColumnAsync($this->selectedColumn);

        $this->store()->updateMeta([
            'mappings_hash' => $this->currentMappingsHash(),
        ]);
    }

    /** @return array<string, string> */
    public function updateMappedValue(string $rawValue, string $newValue): array
    {
        if (blank($newValue)) {
            $this->skipValue($rawValue);

            return [];
        }

        $error = $this->validateValue($this->selectedColumn, $newValue, isCorrection: true);
        $jsonPath = $this->selectedColumnJsonPath();

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

    public function undoCorrection(string $rawValue): void
    {
        $error = $this->validateValue($this->selectedColumn, $rawValue, isCorrection: false);
        $jsonPath = $this->selectedColumnJsonPath();

        $this->connection()->statement('
            UPDATE import_rows
            SET corrections = json_remove(corrections, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        $this->updateValidationForRawValue($jsonPath, $rawValue, $error);

        unset($this->columnErrorStatuses);
    }

    public function skipValue(string $rawValue): void
    {
        $jsonPath = $this->selectedColumnJsonPath();
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

    public function unskipValue(string $rawValue): void
    {
        $jsonPath = $this->selectedColumnJsonPath();

        $this->connection()->statement('
            UPDATE import_rows
            SET skipped = json_remove(skipped, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        unset($this->columnErrorStatuses);
    }

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

    #[Computed]
    public function isSelectedColumnValidating(): bool
    {
        return isset($this->batchIds[$this->selectedColumn->source]);
    }

    /** @return array<string, bool> */
    #[Computed(persist: true, seconds: 60)]
    public function columnErrorStatuses(): array
    {
        $columnSources = $this->columns->pluck('source')->all();

        return ImportRow::getColumnErrorStatuses($this->store()->query(), $columnSources);
    }

    public function continueToPreview(): void
    {
        if ($this->batchIds !== []) {
            return;
        }

        $this->store()->setStatus(ImportStatus::Previewing);
        $this->dispatch('completed');
    }

    private function currentMappingsHash(): string
    {
        $mappings = $this->store()->meta()['column_mappings'] ?? [];

        return hash('xxh128', (string) json_encode($mappings));
    }

    private function hasMappingsChanged(): bool
    {
        $storedHash = $this->store()->meta()['mappings_hash'] ?? null;

        return $storedHash !== $this->currentMappingsHash();
    }
}
