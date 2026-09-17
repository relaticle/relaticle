<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Concerns\ResolvesUpsertMatch;
use App\Enums\CrmEntity;
use App\Models\People;
use App\Models\User;
use Illuminate\Validation\Rule;

final class UpsertPeopleRequest extends BaseCrmEntityRequest
{
    use ResolvesUpsertMatch;

    public function matchedPerson(): ?People
    {
        $matched = $this->resolveMatch(People::class);

        return $matched instanceof People ? $matched : null;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        return [
            ...$this->matchRules('people'),
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('workspace_id', $user->currentWorkspace->getKey())],
        ];
    }

    // A required custom field the caller omitted is already answered by the matched
    // record, which UpdatePeople merges back in, so validation runs as an update.
    protected function existingRecord(): ?People
    {
        return $this->matchedPerson();
    }
}
