<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;

final class StoreNoteRequest extends BaseCrmEntityRequest
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'title' => ['required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
        ];
    }
}
