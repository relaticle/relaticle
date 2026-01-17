<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers\Fields;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A collection of ImportField objects.
 *
 * Provides methods for field lookup, auto-mapping, and merging.
 *
 * @implements IteratorAggregate<int, ImportField>
 */
final readonly class FieldCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, ImportField>  $fields
     */
    private function __construct(
        private array $fields,
    ) {}

    /**
     * Create a new FieldCollection from an array of ImportField objects.
     *
     * @param  array<ImportField>  $fields
     */
    public static function make(array $fields): self
    {
        return new self(array_values($fields));
    }

    /**
     * Create an empty collection.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Get a field by its key.
     */
    public function get(string $key): ?ImportField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Check if a field exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) instanceof \Relaticle\ImportWizardNew\Importers\Fields\ImportField;
    }

    /**
     * Find the best matching field for a CSV column header.
     *
     * Returns the first field that matches the header based on:
     * 1. Exact key match
     * 2. Exact label match
     * 3. Guess alias match
     */
    public function guessFor(string $header): ?ImportField
    {
        foreach ($this->fields as $field) {
            if ($field->matchesHeader($header)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get all required fields.
     *
     * @return array<ImportField>
     */
    public function required(): array
    {
        return array_filter(
            $this->fields,
            fn (ImportField $field): bool => $field->required
        );
    }

    /**
     * Get all optional fields.
     *
     * @return array<ImportField>
     */
    public function optional(): array
    {
        return array_filter(
            $this->fields,
            fn (ImportField $field): bool => ! $field->required
        );
    }

    /**
     * Get standard (non-custom) fields.
     *
     * @return array<ImportField>
     */
    public function standard(): array
    {
        return array_filter(
            $this->fields,
            fn (ImportField $field): bool => ! $field->isCustomField
        );
    }

    /**
     * Get custom fields only.
     *
     * @return array<ImportField>
     */
    public function custom(): array
    {
        return array_filter(
            $this->fields,
            fn (ImportField $field): bool => $field->isCustomField
        );
    }

    /**
     * Get date/datetime fields only.
     *
     * @return array<ImportField>
     */
    public function dateFields(): array
    {
        return array_filter(
            $this->fields,
            fn (ImportField $field): bool => $field->isDateField()
        );
    }

    /**
     * Merge this collection with another.
     */
    public function merge(self $other): self
    {
        return new self(array_merge($this->fields, $other->fields));
    }

    /**
     * Add a single field to the collection.
     */
    public function add(ImportField $field): self
    {
        return new self(array_merge($this->fields, [$field]));
    }

    /**
     * Get all fields as an array.
     *
     * @return array<ImportField>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * Get the count of fields.
     */
    public function count(): int
    {
        return count($this->fields);
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * Check if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Get all field keys.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_map(
            fn (ImportField $field): string => $field->key,
            $this->fields
        );
    }

    /**
     * Filter fields using a callback.
     *
     * @param  callable(ImportField): bool  $callback
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->fields, $callback)));
    }

    /**
     * Map fields using a callback.
     *
     * @template T
     *
     * @param  callable(ImportField): T  $callback
     * @return array<T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->fields);
    }

    /**
     * Convert all fields to array representation.
     *
     * @return array<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            fn (ImportField $field): array => $field->toArray(),
            $this->fields
        );
    }

    /**
     * Get an iterator for the fields.
     *
     * @return Traversable<int, ImportField>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->fields);
    }
}
