<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;

/**
 * Validates import values based on column type and format settings.
 *
 * Supports: Date/DateTime, Float, Choice (single/multi).
 * Returns error message or null if valid.
 */
final class ImportValueValidator
{
    /** @var array<string, array<int, array{label: string, value: string}>> */
    private array $choiceOptionsCache = [];

    public function __construct(
        private readonly string $entityType,
    ) {}

    /**
     * Validate a value against column configuration.
     */
    public function validate(ColumnData $column, mixed $value): ?string
    {
        $type = $column->getType();

        return match (true) {
            $type->isDateOrDateTime() => $this->validateDate($column, (string) $value),
            $type->isFloat() => $this->validateFloat($column, (string) $value),
            $type->isChoiceField() => $this->validateChoice($column, (string) $value),
            default => null,
        };
    }

    /**
     * Validate date/datetime value strictly against selected format.
     *
     * No ISO fallback - validation is strict. Call sites decide what format to use:
     * - Raw CSV data: validate against selected format
     * - Date picker corrections: caller validates against ISO directly
     */
    private function validateDate(ColumnData $column, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $format = $column->dateFormat ?? DateFormat::ISO;
        $withTime = $column->getType()->isTimestamp();
        $parsed = $format->parse($value, $withTime);

        return $parsed === null
            ? "Invalid date format. Expected: {$format->getLabel()}"
            : null;
    }

    /**
     * Validate float value.
     */
    private function validateFloat(ColumnData $column, string $value): ?string
    {
        $format = $column->numberFormat ?? NumberFormat::POINT;
        $parsed = $format->parse($value);

        return $parsed === null ? "Invalid number format. Expected: {$format->getLabel()}" : null;
    }

    /**
     * Validate choice value (single or multi).
     */
    private function validateChoice(ColumnData $column, string $value): ?string
    {
        $options = $this->getChoiceOptions($column);
        $validValues = array_column($options, 'value');

        return in_array($value, $validValues, true)
            ? null
            : 'Invalid choice. Must be one of: '.implode(', ', $validValues);
    }

    /**
     * Get choice options for column (with caching).
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getChoiceOptions(ColumnData $column): array
    {
        $cacheKey = $column->target;

        if (isset($this->choiceOptionsCache[$cacheKey])) {
            return $this->choiceOptionsCache[$cacheKey];
        }

        $customFieldKey = str_replace('custom_fields_', '', $column->target);

        $options = CustomField::query()
            ->forEntity($this->entityType)
            ->where('code', $customFieldKey)
            ->first()
            ?->options()
            ->get()
            ->map(fn ($option) => [
                'label' => $option->name,
                'value' => $option->name,
            ])
            ->toArray() ?? [];

        $this->choiceOptionsCache[$cacheKey] = $options;

        return $options;
    }

    /**
     * Preload all choice options for given columns.
     * Eliminates N+1 queries during bulk validation.
     */
    public function preloadChoiceOptions(iterable $columns): void
    {
        foreach ($columns as $column) {
            if ($column->getType()->isChoiceField()) {
                $this->getChoiceOptions($column);
            }
        }
    }
}
