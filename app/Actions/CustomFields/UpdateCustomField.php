<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
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
            $attributes = array_filter([
                'name' => isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : null,
                'active' => isset($data['active']) ? (bool) $data['active'] : null,
            ], fn (mixed $v): bool => $v !== null);

            if ($attributes !== []) {
                $field->update($attributes);
            }
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->refresh()->load('options');
    }
}
