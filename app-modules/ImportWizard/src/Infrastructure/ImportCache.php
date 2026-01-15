<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Infrastructure;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache operations for the ImportWizard.
 *
 * All cache key management flows through this class to:
 * - Prevent key duplication across Livewire + API
 * - Provide typed access to cached data
 * - Centralize TTL configuration
 */
final readonly class ImportCache
{
    private const int TTL_HOURS = 24;

    /**
     * Get unique values for a CSV column.
     *
     * @return array<string, int> Map of value => occurrence count
     */
    public function getUniqueValues(string $sessionId, string $csvColumn): array
    {
        return Cache::get($this->uniqueValuesKey($sessionId, $csvColumn), []);
    }

    /**
     * Store unique values for a CSV column.
     *
     * @param  array<string, int>  $values  Map of value => occurrence count
     */
    public function putUniqueValues(string $sessionId, string $csvColumn, array $values): void
    {
        Cache::put(
            $this->uniqueValuesKey($sessionId, $csvColumn),
            $values,
            $this->ttl()
        );
    }

    /**
     * Check if unique values exist for a CSV column.
     */
    public function hasUniqueValues(string $sessionId, string $csvColumn): bool
    {
        return Cache::has($this->uniqueValuesKey($sessionId, $csvColumn));
    }

    /**
     * Forget unique values for a CSV column.
     */
    public function forgetUniqueValues(string $sessionId, string $csvColumn): void
    {
        Cache::forget($this->uniqueValuesKey($sessionId, $csvColumn));
    }

    /**
     * Get analysis data for a CSV column.
     *
     * @return array<string, mixed>|null Analysis data including issues, field type, formats
     */
    public function getAnalysis(string $sessionId, string $csvColumn): ?array
    {
        return Cache::get($this->analysisKey($sessionId, $csvColumn));
    }

    /**
     * Store analysis data for a CSV column.
     *
     * @param  array<string, mixed>  $analysis  Analysis data including issues, field type, formats
     */
    public function putAnalysis(string $sessionId, string $csvColumn, array $analysis): void
    {
        Cache::put(
            $this->analysisKey($sessionId, $csvColumn),
            $analysis,
            $this->ttl()
        );
    }

    /**
     * Forget analysis data for a CSV column.
     */
    public function forgetAnalysis(string $sessionId, string $csvColumn): void
    {
        Cache::forget($this->analysisKey($sessionId, $csvColumn));
    }

    /**
     * Get corrections for a field.
     *
     * @return array<string, string> Map of original value => corrected value (empty string = skipped)
     */
    public function getCorrections(string $sessionId, string $fieldName): array
    {
        return Cache::get($this->correctionsKey($sessionId, $fieldName), []);
    }

    /**
     * Store corrections for a field.
     *
     * @param  array<string, string>  $corrections  Map of original value => corrected value
     */
    public function putCorrections(string $sessionId, string $fieldName, array $corrections): void
    {
        if ($corrections === []) {
            $this->forgetCorrections($sessionId, $fieldName);

            return;
        }

        Cache::put(
            $this->correctionsKey($sessionId, $fieldName),
            $corrections,
            $this->ttl()
        );
    }

    /**
     * Add or update a single correction for a field.
     */
    public function setCorrection(string $sessionId, string $fieldName, string $originalValue, string $correctedValue): void
    {
        $corrections = $this->getCorrections($sessionId, $fieldName);
        $corrections[$originalValue] = $correctedValue;
        $this->putCorrections($sessionId, $fieldName, $corrections);
    }

    /**
     * Remove a single correction for a field.
     */
    public function removeCorrection(string $sessionId, string $fieldName, string $originalValue): void
    {
        $corrections = $this->getCorrections($sessionId, $fieldName);
        unset($corrections[$originalValue]);
        $this->putCorrections($sessionId, $fieldName, $corrections);
    }

    /**
     * Forget corrections for a field.
     */
    public function forgetCorrections(string $sessionId, string $fieldName): void
    {
        Cache::forget($this->correctionsKey($sessionId, $fieldName));
    }

    /**
     * Clear all cached data for a session.
     *
     * @param  array<string>  $csvColumns  List of CSV column names
     * @param  array<string>  $fieldNames  List of field names (for corrections)
     */
    public function clearSession(string $sessionId, array $csvColumns, array $fieldNames): void
    {
        foreach ($csvColumns as $csvColumn) {
            $this->forgetUniqueValues($sessionId, $csvColumn);
            $this->forgetAnalysis($sessionId, $csvColumn);
        }

        foreach ($fieldNames as $fieldName) {
            $this->forgetCorrections($sessionId, $fieldName);
        }
    }

    /**
     * Get cache key for unique values.
     */
    public function uniqueValuesKey(string $sessionId, string $csvColumn): string
    {
        return "import:{$sessionId}:values:{$csvColumn}";
    }

    /**
     * Get cache key for analysis data.
     */
    public function analysisKey(string $sessionId, string $csvColumn): string
    {
        return "import:{$sessionId}:analysis:{$csvColumn}";
    }

    /**
     * Get cache key for corrections.
     */
    public function correctionsKey(string $sessionId, string $fieldName): string
    {
        return "import:{$sessionId}:corrections:{$fieldName}";
    }

    /**
     * Get the TTL for cached data.
     */
    private function ttl(): DateTimeInterface
    {
        return now()->addHours(self::TTL_HOURS);
    }
}
