<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers\Fields;

/**
 * Defines a single importable field.
 *
 * ImportField is immutable - all builder methods return new instances.
 */
final readonly class ImportField
{
    /**
     * @param  string  $key  The field key (database column or custom field key)
     * @param  string  $label  Display label
     * @param  bool  $required  Whether the field is required
     * @param  array<string>  $rules  Laravel validation rules
     * @param  array<string>  $guesses  Column name aliases for auto-mapping
     * @param  string|null  $example  Example value for display
     * @param  string|null  $type  Field type hint (text, email, number, date, etc.)
     * @param  bool  $isCustomField  Whether this is a custom field
     */
    private function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public array $rules = [],
        public array $guesses = [],
        public ?string $example = null,
        public ?string $type = null,
        public bool $isCustomField = false,
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
            type: 'id',
        );
    }

    /**
     * Set the display label.
     */
    public function label(string $label): self
    {
        return new self(
            key: $this->key,
            label: $label,
            required: $this->required,
            rules: $this->rules,
            guesses: $this->guesses,
            example: $this->example,
            type: $this->type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Set whether the field is required.
     */
    public function required(bool $required = true): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $required,
            rules: $this->rules,
            guesses: $this->guesses,
            example: $this->example,
            type: $this->type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Set validation rules.
     *
     * @param  array<string>  $rules
     */
    public function rules(array $rules): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $this->required,
            rules: $rules,
            guesses: $this->guesses,
            example: $this->example,
            type: $this->type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Set column name aliases for auto-mapping.
     *
     * @param  array<string>  $aliases
     */
    public function guess(array $aliases): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $this->required,
            rules: $this->rules,
            guesses: $aliases,
            example: $this->example,
            type: $this->type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Set an example value.
     */
    public function example(string $example): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $this->required,
            rules: $this->rules,
            guesses: $this->guesses,
            example: $example,
            type: $this->type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Set the field type.
     */
    public function type(string $type): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $this->required,
            rules: $this->rules,
            guesses: $this->guesses,
            example: $this->example,
            type: $type,
            isCustomField: $this->isCustomField,
        );
    }

    /**
     * Mark as a custom field.
     */
    public function asCustomField(bool $isCustomField = true): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            required: $this->required,
            rules: $this->rules,
            guesses: $this->guesses,
            example: $this->example,
            type: $this->type,
            isCustomField: $isCustomField,
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

    /**
     * Check if this is a date/datetime field.
     */
    public function isDateField(): bool
    {
        return in_array($this->type, ['date', 'datetime'], true);
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'rules' => $this->rules,
            'guesses' => $this->guesses,
            'example' => $this->example,
            'type' => $this->type,
            'isCustomField' => $this->isCustomField,
        ];
    }
}
