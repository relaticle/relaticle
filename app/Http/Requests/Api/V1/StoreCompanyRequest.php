<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends BaseCrmEntityRequest
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_owner_id' => ['sometimes', 'nullable', 'string', Rule::in($user->currentWorkspace->allUsers()->pluck('id')->all())],
        ];
    }
}
