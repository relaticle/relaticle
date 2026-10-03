<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class DeleteCustomField
{
    public function execute(User $user, CustomField $field): void
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
            DB::transaction(function () use ($field): void {
                $field = CustomField::query()->withoutGlobalScopes()->whereKey($field->getKey())->lockForUpdate()->sole();

                CustomFieldDefinitionValidator::forDelete($field);

                // The package observer removes the field's options and stored values with it.
                $field->delete();
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }
}
