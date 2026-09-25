<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use App\Support\CustomFieldSettingsSchema;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class UpdateCustomField
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, CustomField $field, array $data): CustomField
    {
        abort_unless(
            $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage),
            403,
            'Only workspace owners and admins can manage custom field definitions.',
        );

        $workspaceId = $user->currentWorkspace->getKey();

        abort_unless((string) $field->tenant_id === (string) $workspaceId, 404);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            // Re-validated here, not just at proposal time: a rename approved after
            // someone else claimed the name must fail rather than write a duplicate.
            $attributes = CustomFieldDefinitionValidator::forUpdate($user, $field, $data);

            if (array_key_exists('settings', $attributes)) {
                $attributes['settings'] = CustomFieldSettingsSchema::apply($field, $attributes['settings']);
            }

            $field->update($attributes);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->refresh()->load('options');
    }
}
