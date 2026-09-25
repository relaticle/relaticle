<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Rules\ArrayExistsForWorkspace;

final class UpdateNoteRequest extends BaseCrmEntityRequest
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
        $workspaceId = $user->currentWorkspace->getKey();

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForWorkspace('companies', 'company_ids', $workspaceId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForWorkspace('people', 'people_ids', $workspaceId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForWorkspace('opportunities', 'opportunity_ids', $workspaceId)],
        ];
    }
}
