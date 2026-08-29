<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyRequest extends FormRequest
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
        ], new ValidCustomFields($teamId, 'company', isUpdate: true, ignoreEntityId: ($record = $this->route('company')) instanceof Company ? $record->getKey() : null)->toRules($this->input('custom_fields')));
    }
}
