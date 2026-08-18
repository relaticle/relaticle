<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class UpdateCustomField
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, CustomField $field, array $data): CustomField
    {
        abort_unless($user->ownsTeam($user->currentTeam), 403, 'Only team owners can manage custom field definitions.');
        abort_if($field->isSystemDefined(), 422, 'System-defined custom fields cannot be modified.');

        $teamId = $user->currentTeam->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            // Re-validated here, not just at proposal time: a rename approved after
            // someone else claimed the name must fail rather than write a duplicate.
            $attributes = CustomFieldDefinitionValidator::forRename($user, $field, $data);

            $field->update($attributes);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->refresh()->load('options');
    }
}
