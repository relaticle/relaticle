<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePeopleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $teamId = $user->currentTeam->getKey();

        return array_merge([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
        ], new ValidCustomFields($teamId, 'people', isUpdate: true)->toRules($this->input('custom_fields')));
    }
}
