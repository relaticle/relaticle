<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Importers\BaseImporter;

/**
 * Validates entity link values during import.
 *
 * Uses EntityLinkResolver to perform batch lookups and returns validation
 * errors for values that don't match any existing records.
 */
final class EntityLinkValidator
{
    private EntityLinkResolver $resolver;

    public function __construct(string $teamId)
    {
        $this->resolver = new EntityLinkResolver($teamId);
    }

    /**
     * Validate a single value against an entity link.
     *
     * @return string|null Error message or null if valid
     */
    public function validate(EntityLink $link, MatchableField $matcher, mixed $value): ?string
    {
        $value = $this->normalizeValue($value);

        if ($value === '' || $value === null) {
            return null;
        }

        $id = $this->resolver->resolve($link, $matcher, $value);

        if ($id === null) {
            return $this->buildErrorMessage($link, $matcher, $value);
        }

        return null;
    }

    /**
     * Batch validate unique values with efficient database lookup.
     *
     * @param  array<string>  $uniqueValues
     * @return array<string, string|null> Map of value => error message (null if valid)
     */
    public function batchValidate(EntityLink $link, MatchableField $matcher, array $uniqueValues): array
    {
        $results = [];
        $toValidate = [];

        foreach ($uniqueValues as $value) {
            $normalized = $this->normalizeValue($value);
            if ($normalized === '' || $normalized === null) {
                $results[$value] = null;
            } else {
                $toValidate[] = $normalized;
            }
        }

        if ($toValidate === []) {
            return $results;
        }

        $resolved = $this->resolver->batchResolve($link, $matcher, $toValidate);

        foreach ($toValidate as $value) {
            $results[$value] = isset($resolved[$value])
                ? null
                : $this->buildErrorMessage($link, $matcher, $value);
        }

        return $results;
    }

    /**
     * Validate a single value using column context.
     *
     * Convenience method that extracts EntityLink and MatchableField from ColumnData.
     */
    public function validateFromColumn(ColumnData $column, BaseImporter $importer, mixed $value): ?string
    {
        $context = $column->resolveEntityLinkContext($importer);

        return $context
            ? $this->validate($context['link'], $context['matcher'], $value)
            : null;
    }

    /**
     * Batch validate unique values using column context.
     *
     * @param  array<string>  $uniqueValues
     * @return array<string, string|null> Map of value => error message (null if valid)
     */
    public function batchValidateFromColumn(ColumnData $column, BaseImporter $importer, array $uniqueValues): array
    {
        $context = $column->resolveEntityLinkContext($importer);

        return $context
            ? $this->batchValidate($context['link'], $context['matcher'], $uniqueValues)
            : array_fill_keys($uniqueValues, null);
    }

    /**
     * Preload resolver cache with values for faster validation/resolution later.
     *
     * @param  array<mixed>  $values
     */
    public function preloadCache(EntityLink $link, MatchableField $matcher, array $values): void
    {
        $this->resolver->preloadCache($link, $matcher, $values);
    }

    /**
     * Get the resolved ID for a value (after validation or cache preload).
     */
    public function getResolvedId(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $normalized = $this->normalizeValue($value);

        if ($normalized === '' || $normalized === null) {
            return null;
        }

        return $this->resolver->getCachedId($link, $matcher, $normalized)
            ?? $this->resolver->resolve($link, $matcher, $normalized);
    }

    /**
     * Get the underlying resolver instance.
     */
    public function getResolver(): EntityLinkResolver
    {
        return $this->resolver;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function buildErrorMessage(EntityLink $link, MatchableField $matcher, string $value): string
    {
        return "No {$link->label} found matching '{$value}' by {$matcher->label}";
    }
}
