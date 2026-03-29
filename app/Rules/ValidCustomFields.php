<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\CustomField;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Services\ValidationService;

final readonly class ValidCustomFields implements ValidationRule
{
    public function __construct(
        private string $tenantId,
        private string $entityType,
        private bool $isUpdate = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $submittedFields
     * @return array<string, array<int, mixed>>
     */
    public function toRules(mixed $submittedFields = null): array
    {
        $submittedFields = is_array($submittedFields) ? $submittedFields : null;
        $submittedCodes = is_array($submittedFields) ? array_keys($submittedFields) : [];

        $customFields = $this->resolveCustomFields($submittedCodes);

        $rules = ['custom_fields' => ['sometimes', 'array', $this]];

        if ($customFields->isNotEmpty()) {
            $validationService = resolve(ValidationService::class);

            /** @var BaseCustomField $customField */
            foreach ($customFields as $customField) {
                $fieldRules = $validationService->getValidationRules($customField);

                if ($fieldRules !== []) {
                    $fieldRules = $this->ensureNullableForDateFields($customField->type, $fieldRules);
                    $rules["custom_fields.{$customField->code}"] = $fieldRules;
                }

                $itemRules = $validationService->getItemValidationRules($customField);

                if ($itemRules !== []) {
                    $rules["custom_fields.{$customField->code}.*"] = $itemRules;
                }

                $this->addChoiceFieldOptionRules($customField, $rules);
            }
        }

        return $rules;
    }

    /**
     * Validates that all submitted keys are known custom field codes.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $knownCodes = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('entity_type', $this->entityType)
            ->active()
            ->pluck('code')
            ->all();

        $unknownKeys = array_diff(array_keys($value), $knownCodes);

        if ($unknownKeys === []) {
            return;
        }

        $unknownList = implode(', ', $unknownKeys);

        $fail("Unknown custom field keys: {$unknownList}.");
    }

    /**
     * Prepend 'nullable' to validation rules for date/date_time fields so null clears the value.
     *
     * The vendor package's ValidationService does not include 'nullable', causing
     * the 'date' rule to reject null. We fix this at the application layer.
     *
     * @param  array<int, mixed>  $fieldRules
     * @return array<int, mixed>
     */
    private function ensureNullableForDateFields(string $fieldType, array $fieldRules): array
    {
        $fieldTypeData = CustomFieldsType::getFieldType($fieldType);

        if ($fieldTypeData === null || ! $fieldTypeData->dataType->isDateOrDateTime()) {
            return $fieldRules;
        }

        if (in_array('nullable', $fieldRules, true)) {
            return $fieldRules;
        }

        return ['nullable', ...$fieldRules];
    }

    /**
     * Add Rule::in validation for choice fields to ensure submitted option IDs actually exist.
     *
     * For single-choice fields (select, radio): validates the scalar value.
     * For multi-choice fields (multi_select, checkbox_list): validates each array element.
     * Skips fields that accept arbitrary values (e.g., tags) or use a lookup_type.
     *
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function addChoiceFieldOptionRules(BaseCustomField $customField, array &$rules): void
    {
        $fieldTypeData = CustomFieldsType::getFieldType($customField->type);

        if ($fieldTypeData === null) {
            return;
        }

        if (! $fieldTypeData->dataType->isChoiceField()) {
            return;
        }

        if ($fieldTypeData->acceptsArbitraryValues) {
            return;
        }

        if ($customField->lookup_type !== null) {
            return;
        }

        $optionIds = $customField->options->pluck('id')->all();
        $inRule = Rule::in($optionIds);

        $ruleKey = "custom_fields.{$customField->code}";

        if ($fieldTypeData->dataType->isMultiChoiceField()) {
            $rules["{$ruleKey}.*"] = array_merge($rules["{$ruleKey}.*"] ?? [], [$inRule]);
        } else {
            $rules[$ruleKey] = array_merge($rules[$ruleKey] ?? [], [$inRule]);
        }
    }

    /**
     * @param  array<int, string>  $submittedCodes
     * @return EloquentCollection<int, BaseCustomField>
     */
    private function resolveCustomFields(array $submittedCodes): EloquentCollection
    {
        $baseQuery = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('entity_type', $this->entityType)
            ->active()
            ->with('options');

        if ($this->isUpdate) {
            if ($submittedCodes === []) {
                return new EloquentCollection;
            }

            return $baseQuery->whereIn('code', $submittedCodes)->get();
        }

        if ($submittedCodes === []) {
            return $baseQuery
                ->whereJsonContains('validation_rules', [['name' => 'required']])
                ->get();
        }

        return $baseQuery
            ->where(function (Builder $query) use ($submittedCodes): void {
                $query->whereIn('code', $submittedCodes)
                    ->orWhereJsonContains('validation_rules', [['name' => 'required']]);
            })
            ->get();
    }
}
