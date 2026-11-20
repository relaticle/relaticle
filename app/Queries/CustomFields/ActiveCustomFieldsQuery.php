<?php

declare(strict_types=1);

namespace App\Queries\CustomFields;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Collection;

final readonly class ActiveCustomFieldsQuery
{
    /**
     * @return Collection<string, CustomField>
     */
    public function get(string $workspaceId, string $entityType): Collection
    {
        /** @var Collection<string, CustomField> */
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->active()
            ->with('options')
            ->get()
            ->keyBy('code');
    }
}
