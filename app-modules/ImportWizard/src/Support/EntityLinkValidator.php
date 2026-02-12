<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Importers\BaseImporter;

final readonly class EntityLinkValidator
{
    private EntityLinkResolver $resolver;

    public function __construct(string $teamId)
    {
        $this->resolver = new EntityLinkResolver($teamId);
    }

    public function validate(EntityLink $link, MatchableField $matcher, mixed $value): ?string
    {
        if ($matcher->behavior === MatchBehavior::AlwaysCreate) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));

        if ($normalized === '') {
            return null;
        }

        $id = $this->resolver->resolve($link, $matcher, $normalized);

        return $id === null
            ? $this->buildErrorMessage($link, $matcher, $normalized)
            : null;
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, string|null>
     */
    public function batchValidate(EntityLink $link, MatchableField $matcher, array $uniqueValues): array
    {
        if ($matcher->behavior === MatchBehavior::AlwaysCreate) {
            return array_fill_keys($uniqueValues, null);
        }

        $results = [];
        $toValidate = [];

        foreach ($uniqueValues as $value) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                $results[$value] = null;

                continue;
            }

            $toValidate[] = $trimmed;
        }

        if ($toValidate === []) {
            return $results;
        }

        $resolved = $this->resolver->batchResolve($link, $matcher, $toValidate);

        foreach ($toValidate as $value) {
            $results[$value] = $resolved[$value] !== null
                ? null
                : $this->buildErrorMessage($link, $matcher, $value);
        }

        return $results;
    }

    public function validateFromColumn(ColumnData $column, BaseImporter $importer, mixed $value): ?string
    {
        $context = $column->resolveEntityLinkContext($importer);

        return $context
            ? $this->validate($context['link'], $context['matcher'], $value)
            : null;
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, string|null>
     */
    public function batchValidateFromColumn(ColumnData $column, BaseImporter $importer, array $uniqueValues): array
    {
        $context = $column->resolveEntityLinkContext($importer);

        return $context
            ? $this->batchValidate($context['link'], $context['matcher'], $uniqueValues)
            : array_fill_keys($uniqueValues, null);
    }

    /** @param  array<mixed>  $values */
    public function preloadCache(EntityLink $link, MatchableField $matcher, array $values): void
    {
        $this->resolver->preloadCache($link, $matcher, $values);
    }

    public function getResolvedId(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $normalized = trim((string) ($value ?? ''));

        if ($normalized === '') {
            return null;
        }

        return $this->resolver->getCachedId($link, $matcher, $normalized)
            ?? $this->resolver->resolve($link, $matcher, $normalized);
    }

    public function getResolver(): EntityLinkResolver
    {
        return $this->resolver;
    }

    private function buildErrorMessage(EntityLink $link, MatchableField $matcher, string $value): string
    {
        return "No {$link->label} found matching '{$value}' by {$matcher->label}";
    }
}
