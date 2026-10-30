<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\CustomField;
use App\Models\People;
use Illuminate\Database\Eloquent\Builder;

final readonly class PersonByEmailQuery
{
    public function first(string $emailAddress, string $workspaceId): ?People
    {
        $emailField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'people')
            ->where('code', 'emails')
            ->first();

        if (! $emailField instanceof CustomField) {
            return null;
        }

        return People::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('customFieldValues', fn (Builder $valueQuery): Builder => $valueQuery
                ->where('custom_field_id', $emailField->getKey())
                ->whereJsonContains('json_value', $emailAddress))
            ->first(['id', 'name', 'company_id']);
    }
}
