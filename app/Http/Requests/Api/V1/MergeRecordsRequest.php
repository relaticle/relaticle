<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MergeRecordsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'primary_record_id' => ['required', 'string', 'ulid', $this->mergeable()],
            'secondary_record_id' => ['required', 'string', 'ulid', 'different:primary_record_id', $this->mergeable()],
        ];
    }

    private function mergeable(): mixed
    {
        /** @var User $user */
        $user = $this->user();
        $entity = $this->routeIs('people.merge') ? CrmEntity::People : CrmEntity::Company;

        return Rule::exists($entity->table(), 'id')
            ->where('workspace_id', $user->currentWorkspace->getKey())
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id');
    }
}
