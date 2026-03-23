<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\CustomField;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Translation\PotentiallyTranslatedString;
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
     * Returns all validation rules: the array-level rule (this) + per-field value rules.
     *
     * @param  array<string, mixed>|null  $submittedFields
     * @return array<string, array<int, mixed>>
     */
    public function toRules(?array $submittedFields = null): array
    {
        $submittedCodes = is_array($submittedFields) ? array_keys($submittedFields) : [];

        $customFields = $this->resolveCustomFields($submittedCodes);

        $rules = [];

        if ($customFields->isNotEmpty()) {
            $validationService = resolve(ValidationService::class);

            /** @var BaseCustomField $customField */
            foreach ($customFields as $customField) {
                $fieldRules = $validationService->getValidationRules($customField);

                if ($fieldRules !== []) {
                    $rules["custom_fields.{$customField->code}"] = $fieldRules;
                }
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
        $availableList = $knownCodes !== [] ? implode(', ', $knownCodes) : 'none';

        $fail("Unknown custom field keys: {$unknownList}. Available fields for this entity type: {$availableList}.");
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
