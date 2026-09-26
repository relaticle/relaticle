<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\CustomField;
use App\Models\People;
use App\Support\EmailAddress;
use Illuminate\Contracts\Database\Query\Builder;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

final readonly class PersonEmailMatcher
{
    public function firstMatching(string $emailAddress, string $teamId): ?People
    {
        $canonical = EmailAddress::canonicalize($emailAddress);
        $emailField = $this->emailField($teamId);

        if (! $emailField instanceof BaseCustomField || $canonical === '') {
            return null;
        }

        return People::query()
            ->where('workspace_id', $teamId)
            ->whereHas('customFieldValues', fn (Builder $valueQuery): Builder => $valueQuery
                ->withoutGlobalScopes()
                ->where('custom_field_id', $emailField->getKey())
                ->where('tenant_id', $teamId)
                ->whereRaw(
                    'exists (select 1 from json_array_elements_text(custom_field_values.json_value) as t(address) where lower(t.address) = ?)',
                    [$canonical],
                ))
            ->first();
    }

    public function emailField(string $teamId): ?BaseCustomField
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('code', 'emails')
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();
    }
}
