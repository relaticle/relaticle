<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use Illuminate\Support\Facades\Validator;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Importers\BaseImporter;

final class EntityLinkValidator
{
    private readonly EntityLinkResolver $resolver;

    /** @var array<string, array<int, string>> */
    private array $formatRulesCache = [];

    /** @var array<string, string> */
    private array $lastFormatErrors = [];

    public function __construct(private readonly string $teamId)
    {
        $this->resolver = new EntityLinkResolver($this->teamId);
    }

    public function validate(EntityLink $link, MatchableField $matcher, mixed $value): ?string
    {
        if ($matcher->behavior === MatchBehavior::Create) {
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
        $this->lastFormatErrors = [];

        if ($matcher->behavior === MatchBehavior::Create) {
            return array_fill_keys($uniqueValues, null);
        }

        $results = [];
        $toValidate = [];
        $formatRules = $this->getMatchFieldFormatRules($link, $matcher);

        foreach ($uniqueValues as $value) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                $results[$value] = null;

                continue;
            }

            if ($formatRules !== []) {
                $formatError = $this->validateFormat($trimmed, $matcher, $formatRules);

                if ($formatError !== null) {
                    $results[$value] = $formatError;
                    $this->lastFormatErrors[$value] = $formatError;

                    continue;
                }
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

    /** @return array<string, string> */
    public function getLastFormatErrors(): array
    {
        return $this->lastFormatErrors;
    }

    /**
     * @return array<int, string>
     */
    private function getMatchFieldFormatRules(EntityLink $link, MatchableField $matcher): array
    {
        if (! str_starts_with($matcher->field, 'custom_fields_')) {
            return [];
        }

        $cacheKey = "{$link->targetEntity}:{$matcher->field}";

        if (isset($this->formatRulesCache[$cacheKey])) {
            return $this->formatRulesCache[$cacheKey];
        }

        $code = substr($matcher->field, strlen('custom_fields_'));

        $customField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $link->targetEntity)
            ->where('code', $code)
            ->first();

        if ($customField === null) {
            return $this->formatRulesCache[$cacheKey] = [];
        }

        $fieldTypeInstance = CustomFieldsType::getFieldTypeInstance($customField->type);

        if ($fieldTypeInstance === null) {
            return $this->formatRulesCache[$cacheKey] = [];
        }

        return $this->formatRulesCache[$cacheKey] = $fieldTypeInstance->configure()->getDefaultItemValidationRules();
    }

    /**
     * @param  array<int, string>  $rules
     */
    private function validateFormat(string $value, MatchableField $matcher, array $rules): ?string
    {
        $items = $matcher->multiValue
            ? array_map(trim(...), explode(',', $value))
            : [$value];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            $validator = Validator::make(['value' => $item], ['value' => $rules]);

            if ($validator->fails()) {
                return "Invalid format: '{$item}'";
            }
        }

        return null;
    }

    private function buildErrorMessage(EntityLink $link, MatchableField $matcher, string $value): string
    {
        return "No {$link->label} found matching '{$value}' by {$matcher->label}";
    }
}
