<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField\Concerns;

use App\Models\CustomField;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

trait ResolvesOwnedCustomField
{
    /**
     * Resolve a custom field definition owned by the team, identified by its
     * entity type and machine code. Deactivated fields are included so they can
     * still be managed. Returns null when no matching field exists.
     */
    private function resolveOwnedCustomField(int|string $teamId, string $entityType, string $code): ?BaseCustomField
    {
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            return CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->where('code', $code)
                ->first();
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }
}
