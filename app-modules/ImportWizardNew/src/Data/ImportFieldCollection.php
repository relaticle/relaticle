<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Illuminate\Support\Collection;

/**
 * A typed collection of ImportField objects.
 *
 * Extends Laravel's Collection to provide domain-specific methods
 * for working with import fields while inheriting all Collection functionality.
 *
 * @extends Collection<int, ImportField>
 */
final class ImportFieldCollection extends Collection
{
    /**
     * Get a field by its key.
     *
     * @param  mixed  $key
     * @param  mixed  $default
     */
    public function get($key, $default = null): ?ImportField
    {
        return $this->first(fn (ImportField $f): bool => $f->key === $key) ?? $default;
    }

    /**
     * Check if a field with the given key exists.
     */
    public function hasKey(string $key): bool
    {
        return $this->get($key) instanceof \Relaticle\ImportWizardNew\Data\ImportField;
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
        return $this->first(fn (ImportField $f): bool => $f->matchesHeader($header));
    }

    /**
     * Get all required fields.
     */
    public function required(): static
    {
        return $this->filter(fn (ImportField $f): bool => $f->required);
    }

    /**
     * Get all optional fields.
     */
    public function optional(): static
    {
        return $this->reject(fn (ImportField $f): bool => $f->required);
    }

    /**
     * Get standard (non-custom) fields.
     */
    public function standard(): static
    {
        return $this->reject(fn (ImportField $f): bool => $f->isCustomField);
    }

    /**
     * Get custom fields only.
     */
    public function custom(): static
    {
        return $this->filter(fn (ImportField $f): bool => $f->isCustomField);
    }
}
