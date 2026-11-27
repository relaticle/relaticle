<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MergeRecordsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'primary_record_id' => ['bail', 'required', 'string', 'ulid', $this->mergeable()],
            'secondary_record_id' => ['bail', 'required', 'string', 'ulid', 'different:primary_record_id', $this->mergeable()],
        ];
    }

    /**
     * @return array{0: Company|People, 1: Company|People}
     */
    public function records(): array
    {
        $people = $this->routeIs('people.merge');
        $primary = $this->find($this->string('primary_record_id')->toString(), $people);
        $secondary = $this->find($this->string('secondary_record_id')->toString(), $people);

        if ($primary === null || $secondary === null) {
            throw ValidationException::withMessages(['records' => __('filament/record-page.merge.unavailable')]);
        }

        return [$primary, $secondary];
    }

    private function find(string $id, bool $people): Company|People|null
    {
        return $people ? People::query()->find($id) : Company::query()->find($id);
    }

    private function entity(): CrmEntity
    {
        return $this->routeIs('people.merge') ? CrmEntity::People : CrmEntity::Company;
    }

    private function mergeable(): mixed
    {
        /** @var User $user */
        $user = $this->user();

        return Rule::exists($this->entity()->table(), 'id')
            ->where('workspace_id', $user->currentWorkspace->getKey())
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id');
    }
}
