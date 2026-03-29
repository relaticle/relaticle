<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOpportunityRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'contact_id' => ['nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
        ], new ValidCustomFields($teamId, 'opportunity')->toRules($this->input('custom_fields')));
    }
}
