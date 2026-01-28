<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\CustomFields\Enums\FieldDataType;
use Spatie\LaravelData\Data;

/**
 * Defines a single importable field.
 *
 * ImportField is immutable - all builder methods return new instances.
 */
final class ImportField extends Data
{
    /**
     * @param  string  $key  The field key (database column or custom field key)
     * @param  string  $label  Display label
     * @param  bool  $required  Whether the field is required
     * @param  array<string>  $rules  Laravel validation rules
     * @param  array<string>  $guesses  Column name aliases for auto-mapping
     * @param  string|null  $example  Example value for display
     * @param  bool  $isCustomField  Whether this is a custom field
     * @param  FieldDataType|null  $type  The data type
     * @param  string|null  $icon  The display icon (Heroicon name)
     * @param  int|null  $sortOrder  Display order for custom fields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $rules = [],
        public readonly array $guesses = [],
        public readonly ?string $example = null,
        public readonly bool $isCustomField = false,
        public readonly ?FieldDataType $type = null,
        public readonly ?string $icon = null,
        public readonly ?int $sortOrder = null,
    ) {}

    /**
     * Create a new ImportField.
     */
    public static function make(string $key): self
    {
        return new self(
            key: $key,
            label: ucfirst(str_replace('_', ' ', $key)),
        );
    }

    /**
     * Create a pre-configured ID field for record matching.
     */
    public static function id(): self
    {
        return new self(
            key: 'id',
            label: 'Record ID',
            required: false,
            rules: ['nullable', 'ulid'],
            guesses: ['id', 'record_id', 'ulid', 'record id'],
            example: '01KCCFMZ52QWZSQZWVG0AP704V',
            icon: 'heroicon-o-finger-print',
        );
    }

    /**
     * Set the display label.
     */
    public function label(string $label): self
    {
        return $this->cloneWith(['label' => $label]);
    }

    /**
     * Set whether the field is required.
     */
    public function required(bool $required = true): self
    {
        return $this->cloneWith(['required' => $required]);
    }

    /**
     * Set validation rules.
     *
     * @param  array<string>  $rules
     */
    public function rules(array $rules): self
    {
        return $this->cloneWith(['rules' => $rules]);
    }

    /**
     * Set column name aliases for auto-mapping.
     *
     * @param  array<string>  $aliases
     */
    public function guess(array $aliases): self
    {
        return $this->cloneWith(['guesses' => $aliases]);
    }

    /**
     * Set an example value.
     */
    public function example(string $example): self
    {
        return $this->cloneWith(['example' => $example]);
    }

    /**
     * Mark as a custom field.
     */
    public function asCustomField(bool $isCustomField = true): self
    {
        return $this->cloneWith(['isCustomField' => $isCustomField]);
    }

    /**
     * Set the data type.
     */
    public function type(?FieldDataType $type): self
    {
        return $this->cloneWith(['type' => $type]);
    }

    /**
     * Set the display icon.
     */
    public function icon(?string $icon): self
    {
        return $this->cloneWith(['icon' => $icon]);
    }

    /**
     * Set the sort order.
     */
    public function sortOrder(?int $sortOrder): self
    {
        return $this->cloneWith(['sortOrder' => $sortOrder]);
    }

    /**
     * Create a new instance with specified property overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            key: $overrides['key'] ?? $this->key,
            label: $overrides['label'] ?? $this->label,
            required: $overrides['required'] ?? $this->required,
            rules: $overrides['rules'] ?? $this->rules,
            guesses: $overrides['guesses'] ?? $this->guesses,
            example: $overrides['example'] ?? $this->example,
            isCustomField: $overrides['isCustomField'] ?? $this->isCustomField,
            type: $overrides['type'] ?? $this->type,
            icon: $overrides['icon'] ?? $this->icon,
            sortOrder: $overrides['sortOrder'] ?? $this->sortOrder,
        );
    }

    /**
     * Check if this field matches a given column header.
     */
    public function matchesHeader(string $header): bool
    {
        $normalized = strtolower(trim($header));

        if ($normalized === strtolower($this->key)) {
            return true;
        }

        if ($normalized === strtolower($this->label)) {
            return true;
        }

        return array_any($this->guesses, fn ($guess): bool => strtolower($guess) === $normalized);
    }
}
