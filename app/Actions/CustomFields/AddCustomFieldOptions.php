<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class AddCustomFieldOptions
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Model
    {
        abort_unless($user->ownsTeam($user->currentTeam), 403, 'Only team owners can manage custom field definitions.');

        $fieldId = $data['_record_id'] ?? null;

        abort_if(! is_string($fieldId) && ! is_int($fieldId), 422, 'Missing field ID (_record_id).');

        $teamId = $user->currentTeam->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            $field = CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->findOrFail($fieldId);

            abort_unless(
                in_array($field->type, CreateCustomField::CHOICE_TYPES, true),
                422,
                "Field type \"{$field->type}\" does not support options. Only select, multi-select, radio, checkbox-list, and toggle-buttons fields can have options.",
            );

            // Re-validated here, not just at proposal time: options approved after the
            // same labels were added elsewhere must fail rather than write duplicates.
            $validated = CustomFieldDefinitionValidator::forNewOptions($user, $field, $data);

            $nextSortOrder = (int) $field->options()->withoutGlobalScopes()->max('sort_order') + 1;

            foreach (array_column($validated['options'], 'name') as $index => $optionName) {
                $field->options()->create([
                    config('custom-fields.database.column_names.tenant_foreign_key') => $teamId,
                    'name' => $optionName,
                    'sort_order' => $nextSortOrder + $index,
                ]);
            }
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->refresh()->load('options');
    }
}
