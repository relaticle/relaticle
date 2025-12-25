<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use Illuminate\Support\Str;
use Relaticle\CustomFields\Enums\CustomFieldWidth;

/**
 * Trait for custom field enums to provide configuration data
 *
 * This trait provides a standardized interface for custom field enums
 * and default implementations for optional methods.
 */
trait CustomFieldTrait
{
    /**
     * Get the display name for the field
     *
     * @return string The human-readable name of the field
     */
    public function getDisplayName(): string
    {
        return Str::title($this->name);
    }

    /**
     * Get the field type
     *
     * @return string The type of form field to use
     */
    abstract public function getFieldType(): string;

    /**
     * Get whether this field is system defined
     *
     * System-defined fields cannot be deleted by users, only deactivated.
     *
     * @return bool True if the field is system defined
     */
    public function isSystemDefined(): bool
    {
        return true;
    }

    /**
     * Get whether the field is hidden in list toggle
     *
     * Fields that aren't toggleable hidden are always shown in lists.
     *
     * @return bool True if the field can be hidden in list view
     */
    public function isListToggleableHidden(): bool
    {
        return true;
    }

    /**
     * Get the field width
     *
     * @return CustomFieldWidth The width of the field or null for default width
     */
    public function getWidth(): CustomFieldWidth
    {
        return CustomFieldWidth::_100;
    }

    /**
     * Get options for select fields
     *
     * @return array<int|string, string>|null Array of options for select/multi-select fields or null if not applicable
     */
    public function getOptions(): ?array
    {
        return null;
    }

    /**
     * Get field description (tooltip)
     *
     * @return string|null The field description or null if not provided
     */
    public function getDescription(): ?string
    {
        return null;
    }

    /**
     * Get color mapping for select field options
     *
     * @return array<int|string, string>|null Array of option => color mappings or null if not applicable
     */
    public function getOptionColors(): ?array
    {
        return null;
    }

    /**
     * Get whether this field should have color options enabled
     *
     * @return bool True if color options should be enabled
     */
    public function hasColorOptions(): bool
    {
        return $this->getOptionColors() !== null;
    }

    /**
     * Get whether this field allows multiple values
     *
     * @return bool True if multiple values are allowed
     */
    public function allowsMultipleValues(): bool
    {
        return false;
    }

    /**
     * Get the maximum number of values allowed for this field
     *
     * @return int Maximum values (1 for single value fields)
     */
    public function getMaxValues(): int
    {
        return $this->allowsMultipleValues() ? 5 : 1;
    }

    /**
     * Get whether this field value must be unique per entity type
     *
     * @return bool True if values must be unique across all records of this entity type
     */
    public function isUniquePerEntityType(): bool
    {
        return false;
    }

    /**
     * Get complete field configuration
     *
     * @return array{
     *     name: string,
     *     type: string,
     *     systemDefined: bool,
     *     listToggleableHidden: bool,
     *     width: CustomFieldWidth|null,
     *     options: array<int|string, string>|null,
     *     description: string|null,
     *     optionColors: array<int|string, string>|null,
     *     hasColorOptions: bool,
     *     allowsMultipleValues: bool
     * } The complete field configuration
     */
    public function getConfiguration(): array
    {
        return [
            'name' => $this->getDisplayName(),
            'type' => $this->getFieldType(),
            'systemDefined' => $this->isSystemDefined(),
            'listToggleableHidden' => $this->isListToggleableHidden(),
            'width' => $this->getWidth(),
            'options' => $this->getOptions(),
            'description' => $this->getDescription(),
            'optionColors' => $this->getOptionColors(),
            'hasColorOptions' => $this->hasColorOptions(),
            'allowsMultipleValues' => $this->allowsMultipleValues(),
        ];
    }
}
