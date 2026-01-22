<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ColumnMapping;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ColumnAnalyzer;
use Relaticle\ImportWizard\Support\ValueValidator;
use RuntimeException;

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

    public string $selectedColumn = '';

    public int $valuesPage = 1;

    public int $perPage = 100;

    public string $search = '';

    public string $filter = 'all';

    public string $sortField = 'count';

    public string $sortDirection = 'desc';

    /** @var array<int, array<string, mixed>> */
    public array $loadedValues = [];

    public int $totalFiltered = 0;

    private ?BaseImporter $importer = null;

    private ?ColumnAnalyzer $analyzer = null;

    /** @var array<string, array<int, array{value: string, label: string}>> */
    private array $cachedChoiceOptions = [];

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);

        $this->selectedColumn = $this->store()->mappings()->first()->source;
        $this->loadInitialValues();
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.review-step');
    }

    /**
     * Get mapped columns for sidebar display.
     *
     * @return array<int, array{source: string, label: string}>
     */
    #[Computed]
    public function mappedColumns(): array
    {
        $importer = $this->getImporter();
        $columns = [];

        foreach ($this->store()->mappings() as $mapping) {
            $field = $mapping->isRelationshipMapping()
                ? null
                : $importer->allFields()->get($mapping->target);

            $columns[] = [
                'source' => $mapping->source,
                'label' => $field !== null ? $field->label : $mapping->target,
            ];
        }

        return $columns;
    }

    #[Computed]
    public function selectedMapping(): ?ColumnMapping
    {
        return $this->store()->getMapping($this->selectedColumn);
    }

    #[Computed]
    public function selectedField(): ?ImportField
    {
        $mapping = $this->selectedMapping();

        if ($mapping === null || $mapping->isRelationshipMapping()) {
            return null;
        }

        return $this->getImporter()->allFields()->get($mapping->target);
    }

    /**
     * Get stats for the selected column (fetched on-demand).
     *
     * @return array{uniqueCount: int, blankCount: int}
     */
    #[Computed]
    public function selectedColumnStats(): array
    {
        if ($this->selectedColumn === '') {
            return ['uniqueCount' => 0, 'blankCount' => 0];
        }

        return $this->getAnalyzer()->getColumnStats($this->selectedColumn);
    }

    #[Computed]
    public function totalPages(): int
    {
        return $this->totalFiltered > 0 ? (int) ceil($this->totalFiltered / $this->perPage) : 1;
    }

    #[Computed]
    public function isSelectedColumnDateType(): bool
    {
        return $this->selectedField()?->type?->isDateOrDateTime() ?? false;
    }

    #[Computed]
    public function selectedColumnDateFormat(): DateFormat
    {
        return $this->selectedMapping()?->dateFormat ?: DateFormat::ISO;
    }

    #[Computed]
    public function isSelectedColumnDateTime(): bool
    {
        return $this->selectedField()?->type === FieldDataType::DATE_TIME;
    }

    /**
     * Get date format options for the currently selected column.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    #[Computed]
    public function dateFormatOptions(): array
    {
        $withTime = $this->isSelectedColumnDateTime();

        return collect(DateFormat::cases())
            ->map(fn (DateFormat $format): array => [
                'value' => $format->value,
                'label' => $format->getLabel(),
                'description' => implode(' • ', $format->getExamples($withTime)),
            ])
            ->all();
    }

    #[Computed]
    public function isSelectedColumnChoiceType(): bool
    {
        return $this->selectedField()?->type?->isChoiceField() ?? false;
    }

    #[Computed]
    public function isSelectedColumnMultiChoice(): bool
    {
        return $this->selectedField()?->type?->isMultiChoiceField() ?? false;
    }

    /**
     * Get choice options for the currently selected column.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function selectedColumnOptions(): array
    {
        $field = $this->selectedField();

        if ($field === null || ! $field->isCustomField || ! $field->type?->isChoiceField()) {
            return [];
        }

        if (isset($this->cachedChoiceOptions[$field->key])) {
            return $this->cachedChoiceOptions[$field->key];
        }

        $code = Str::after($field->key, 'custom_fields_');
        $options = $this->loadChoiceOptions($code);

        $this->cachedChoiceOptions[$field->key] = $options;

        return $options;
    }

    /**
     * Load choice options from the custom field.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function loadChoiceOptions(string $code): array
    {
        $customField = CustomFields::customFieldModel()::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->getImporter()->getTeamId())
            ->where('code', $code)
            ->with('options')
            ->first();

        if ($customField === null) {
            return [];
        }

        return $customField->options->map(fn ($option): array => [
            'value' => $option->name,
            'label' => $option->name,
        ])->all();
    }

    /**
     * Get the validation error for a raw value in the selected column.
     */
    public function getValidationError(string $rawValue): ?string
    {
        $field = $this->selectedField();

        if ($field === null) {
            return null;
        }

        return app(ValueValidator::class)->validate(
            field: $field,
            value: $rawValue,
            dateFormat: $this->selectedColumnDateFormat(),
            choiceOptions: $this->selectedColumnOptions(),
        );
    }

    /**
     * Normalize choice values to match option casing and filter to valid options only.
     *
     * @return string|list<string>
     */
    public function normalizeChoiceValue(string $rawValue): string|array
    {
        $options = collect($this->selectedColumnOptions());

        if ($this->isSelectedColumnMultiChoice()) {
            $values = array_map(trim(...), explode(',', $rawValue));

            return array_values(array_filter(
                array_map(function (string $v) use ($options): ?string {
                    $match = $options->first(fn (array $o): bool => strcasecmp((string) $o['value'], $v) === 0);

                    return $match['value'] ?? null;
                }, $values)
            ));
        }

        $match = $options->first(fn (array $o): bool => strcasecmp((string) $o['value'], $rawValue) === 0);

        return $match['value'] ?? $rawValue;
    }

    public function selectColumn(string $csvColumn): void
    {
        $mapping = $this->store()->getMapping($csvColumn);

        if ($mapping !== null) {
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

    public function setDateFormat(string $format): void
    {
        $dateFormatEnum = DateFormat::tryFrom($format);

        if ($dateFormatEnum === null || ! $this->isSelectedColumnDateType()) {
            return;
        }

        $mapping = $this->selectedMapping();

        if ($mapping !== null) {
            $this->store()->updateMapping($this->selectedColumn, $mapping->withDateFormat($dateFormatEnum));
        }

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

        if ($this->filter === 'modified') {
            $this->removeValueFromList($value);
        } else {
            $this->updateValueInPlace($value, '');
        }
    }

    public function updateMappedValue(string $csvColumn, string $rawValue, string $newValue): void
    {
        $trimmedNew = DateFormat::normalizePickerValue($newValue);

        $this->getAnalyzer()->applyCorrection($csvColumn, $rawValue, $trimmedNew);

        $isRestored = $trimmedNew === trim($rawValue);
        $mappedValue = $isRestored ? null : $trimmedNew;

        $shouldRemove = match ($this->filter) {
            'modified' => $isRestored,
            'skipped' => true,
            default => false,
        };

        if ($shouldRemove) {
            $this->removeValueFromList($rawValue);
        } else {
            $this->updateValueInPlace($rawValue, $mappedValue);
        }
    }

    public function continueToPreview(): void
    {
        $this->store()->setStatus(ImportStatus::Importing);
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

    private function getAnalyzer(): ColumnAnalyzer
    {
        if (! $this->analyzer instanceof ColumnAnalyzer) {
            $store = $this->store();
            throw_unless($store instanceof ImportStore, RuntimeException::class, 'ImportStore not available');

            $this->analyzer = new ColumnAnalyzer($store);
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
