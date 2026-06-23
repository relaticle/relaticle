<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
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

            $newOptions = is_array($data['options'] ?? null) ? $data['options'] : [];
            abort_if($newOptions === [], 422, 'At least one option must be provided.');

            $maxOptions = (int) config('chat.max_field_options', 50);
            $existingCount = $field->options()->withoutGlobalScopes()->count();

            abort_if(
                ($existingCount + count($newOptions)) > $maxOptions,
                422,
                "Adding these options would exceed the limit of {$maxOptions} options per field.",
            );

            $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
            $nextSortOrder = (int) $field->options()->withoutGlobalScopes()->max('sort_order') + 1;

            foreach (array_values($newOptions) as $index => $option) {
                $optionName = is_array($option) ? (string) ($option['name'] ?? '') : (string) $option;
                $field->options()->create([
                    $tenantKey => $teamId,
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
