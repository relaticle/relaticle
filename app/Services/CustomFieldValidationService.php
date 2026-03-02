<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomField;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Services\ValidationService;

/**
 * Resolves active custom fields and generates Laravel validation rules.
 *
 * Shared by API FormRequests and MCP tools to eliminate duplication.
 */
final readonly class CustomFieldValidationService
{
    /**
     * @param  array<string, mixed>|null  $submittedFields
     * @return array<string, array<int, mixed>>
     */
    public static function rules(
        string $tenantId,
        string $entityType,
        ?array $submittedFields = null,
        bool $isUpdate = false,
    ): array {
        $submittedCodes = is_array($submittedFields) ? array_keys($submittedFields) : [];

        $customFields = self::resolveCustomFields($tenantId, $entityType, $submittedCodes, $isUpdate);

        if ($customFields->isEmpty()) {
            return [];
        }

        $validationService = resolve(ValidationService::class);
        $rules = [];

        /** @var BaseCustomField $customField */
        foreach ($customFields as $customField) {
            $fieldRules = $validationService->getValidationRules($customField);

            if ($fieldRules !== []) {
                $rules["custom_fields.{$customField->code}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $submittedCodes
     * @return EloquentCollection<int, BaseCustomField>
     */
    private static function resolveCustomFields(
        string $tenantId,
        string $entityType,
        array $submittedCodes,
        bool $isUpdate,
    ): EloquentCollection {
        $baseQuery = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->active()
            ->with('options');

        if ($isUpdate) {
            if ($submittedCodes === []) {
                return new EloquentCollection;
            }

            return $baseQuery->whereIn('code', $submittedCodes)->get();
        }

        if ($submittedCodes === []) {
            return $baseQuery
                ->whereJsonContains('validation_rules', ['name' => 'required'])
                ->get();
        }

        return $baseQuery
            ->where(function (Builder $query) use ($submittedCodes): void {
                $query->whereIn('code', $submittedCodes)
                    ->orWhereJsonContains('validation_rules', ['name' => 'required']);
            })
            ->get();
    }
}
