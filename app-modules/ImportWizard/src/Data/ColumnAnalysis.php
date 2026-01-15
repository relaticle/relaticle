<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Illuminate\Support\Collection;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Analysis results for a single CSV column including unique values and validation issues.
 */
final class ColumnAnalysis extends Data
{
    /**
     * @param  array<string, int>  $uniqueValues  Map of value to occurrence count (stored in cache, not serialized)
     * @param  DataCollection<int, ValueIssue>  $issues  Full issues (only in cache, NOT in Livewire state)
     * @param  int|null  $issueCount  Pre-computed count (used in Livewire state when issues aren't serialized)
     * @param  int|null  $errorCount  Pre-computed error count (used in Livewire state when issues aren't serialized)
     * @param  int|null  $warningCount  Pre-computed warning count (used in Livewire state when issues aren't serialized)
     */
    public function __construct(
        public readonly string $csvColumnName,
        public readonly string $mappedToField,
        public readonly string $fieldType,
        public readonly int $totalValues,
        public readonly int $uniqueCount,
        public readonly int $blankCount,
        public readonly array $uniqueValues = [],
        #[DataCollectionOf(ValueIssue::class)]
        public readonly DataCollection $issues = new DataCollection(ValueIssue::class, []),
        public readonly bool $isRequired = false,
        public readonly ?DateFormat $detectedDateFormat = null,
        public readonly ?DateFormat $selectedDateFormat = null,
        public readonly ?float $dateFormatConfidence = null,
        public readonly ?int $issueCount = null,
        public readonly ?int $errorCount = null,
        public readonly ?int $warningCount = null,
    ) {}

    /**
     * Check if this column is a date or datetime field.
     */
    public function isDateField(): bool
    {
        $dataType = FieldDataType::tryFrom($this->fieldType);

        return $dataType === FieldDataType::DATE || $dataType === FieldDataType::DATE_TIME;
    }

    /**
     * Check if this column is a date-only field (no time component).
     */
    public function isDateOnlyField(): bool
    {
        return $this->fieldType === FieldDataType::DATE->value;
    }

    /**
     * Check if this column is a datetime field (with time component).
     */
    public function isDateTimeField(): bool
    {
        return $this->fieldType === FieldDataType::DATE_TIME->value;
    }

    /**
     * Get the effective date format (selected or detected).
     */
    public function getEffectiveDateFormat(): ?DateFormat
    {
        return $this->selectedDateFormat ?? $this->detectedDateFormat;
    }

    /**
     * Check if date format needs user confirmation (low confidence).
     */
    public function needsDateFormatConfirmation(): bool
    {
        return $this->isDateField()
            && $this->dateFormatConfidence !== null
            && $this->dateFormatConfidence < 0.8
            && ! $this->selectedDateFormat instanceof DateFormat;
    }

    /**
     * Check if this column is a single or multi choice field.
     */
    public function isChoiceField(): bool
    {
        $dataType = FieldDataType::tryFrom($this->fieldType);

        return $dataType?->isChoiceField() ?? false;
    }

    /**
     * Check if this column is a multi-choice field.
     */
    public function isMultiChoiceField(): bool
    {
        $dataType = FieldDataType::tryFrom($this->fieldType);

        return $dataType === FieldDataType::MULTI_CHOICE;
    }

    /**
     * Check if this column is a single-choice field.
     */
    public function isSingleChoiceField(): bool
    {
        $dataType = FieldDataType::tryFrom($this->fieldType);

        return $dataType === FieldDataType::SINGLE_CHOICE;
    }

    /**
     * Get the values that are missing from the choice options.
     * For choice fields, validation errors are typically due to invalid option values.
     *
     * @return array<string>
     */
    public function getMissingChoiceOptions(): array
    {
        if (! $this->isChoiceField()) {
            return [];
        }

        // For choice fields, validation errors indicate values not in the options list
        // Laravel generates "The selected {field} is invalid" messages
        return $this->errorIssues()
            ->filter(fn (ValueIssue $issue): bool => $issue->value !== '' && str_contains(strtolower($issue->message), 'invalid'))
            ->pluck('value')
            ->all();
    }

    /**
     * Check if this column has missing choice options that can be created.
     */
    public function hasMissingChoiceOptions(): bool
    {
        return $this->isChoiceField() && $this->getMissingChoiceOptions() !== [];
    }

    /**
     * Get unique values for display with "load more" pattern.
     *
     * @return array<string, int>
     */
    public function paginatedValues(int $page = 1, int $perPage = 100, ?string $search = null): array
    {
        $values = $this->uniqueValues;

        if ($search !== null && $search !== '') {
            $values = array_filter(
                $values,
                fn (int $count, string $value): bool => str_contains(strtolower($value), strtolower($search)),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return array_slice($values, 0, $page * $perPage, preserve_keys: true);
    }

    /**
     * Check if this column has any validation errors.
     */
    public function hasErrors(): bool
    {
        // Use pre-computed count if available (from Livewire state where full issues aren't serialized)
        if ($this->errorCount !== null) {
            return $this->errorCount > 0;
        }

        return $this->errorIssues()->isNotEmpty();
    }

    /**
     * Get the count of validation errors in this column.
     */
    public function getErrorCount(): int
    {
        // Use pre-computed count if available (from Livewire state where full issues aren't serialized)
        if ($this->errorCount !== null) {
            return $this->errorCount;
        }

        return $this->errorIssues()->count();
    }

    /**
     * Get the validation issue for a specific value, if any.
     */
    public function getIssueForValue(string $value): ?ValueIssue
    {
        return $this->issues->toCollection()->firstWhere('value', $value);
    }

    /**
     * Get unique values that have errors, with "load more" pattern.
     *
     * @return array<string, int>
     */
    public function paginatedErrorValues(int $page = 1, int $perPage = 100): array
    {
        $errorValues = $this->errorIssues()->pluck('value')->all();

        $filteredValues = array_filter(
            $this->uniqueValues,
            fn (int $count, string $value): bool => in_array($value, $errorValues, true),
            ARRAY_FILTER_USE_BOTH
        );

        return array_slice($filteredValues, 0, $page * $perPage, preserve_keys: true);
    }

    /**
     * @return Collection<int, ValueIssue>
     */
    private function errorIssues(): Collection
    {
        /** @var Collection<int, ValueIssue> */
        return once(fn () => $this->issues->toCollection()->where('severity', 'error')->values());
    }
}
