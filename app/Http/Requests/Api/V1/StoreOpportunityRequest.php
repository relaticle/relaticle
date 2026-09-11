<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Validation\Rule;

final class StoreOpportunityRequest extends BaseCrmEntityRequest
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'contact_id' => ['nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
        ];
    }
}
