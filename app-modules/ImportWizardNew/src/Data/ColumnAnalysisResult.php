<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizardNew\Enums\DateFormat;
use Spatie\LaravelData\Data;

/**
 * Analysis metadata for a single column.
 *
 * Stores summary statistics rather than full value lists to prevent
 * PayloadTooLargeException with large imports.
 */
final class ColumnAnalysisResult extends Data
{
    /**
     * @param  string  $csvColumn  The source CSV column name
     * @param  string  $fieldKey  The target field key
     * @param  string  $fieldLabel  Human-readable field label
     * @param  string  $fieldType  The field type (field or custom_field)
     * @param  int  $totalRows  Total number of rows
     * @param  int  $uniqueCount  Count of unique values
     * @param  int  $blankCount  Count of blank values
     * @param  bool  $isRequired  Whether the field is required
     * @param  string|null  $relationship  Relationship name if this is a relationship mapping
     * @param  FieldDataType|null  $dataType  The data type
     * @param  DateFormat|null  $dateFormat  Currently selected date format
     */
    public function __construct(
        public readonly string $csvColumn,
        public readonly string $fieldKey,
        public readonly string $fieldLabel,
        public readonly string $fieldType,
        public readonly int $totalRows,
        public readonly int $uniqueCount,
        public readonly int $blankCount,
        public readonly bool $isRequired,
        public readonly ?string $relationship = null,
        public readonly ?FieldDataType $dataType = null,
        public readonly ?DateFormat $dateFormat = null,
    ) {}

    /**
     * Check if this column is a date or datetime type.
     */
    public function isDateOrDateTime(): bool
    {
        return $this->dataType?->isDateOrDateTime() ?? false;
    }

    /**
     * Check if this column is a choice (single or multi) type.
     */
    public function isChoiceField(): bool
    {
        return $this->dataType?->isChoiceField() ?? false;
    }

    /**
     * Check if this column is a multi-choice type.
     */
    public function isMultiChoice(): bool
    {
        return $this->dataType?->isMultiChoiceField() ?? false;
    }
}
