<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Http\Concerns\NormalizesCustomFields;
use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePeopleRequest extends FormRequest
{
    use NormalizesCustomFields;

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $teamId = $user->currentTeam->getKey();

        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
        ], new ValidCustomFields($teamId, $this->entity()->value)->toRules($this->input('custom_fields')));
    }
}
