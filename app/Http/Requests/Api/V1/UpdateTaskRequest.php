<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTaskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $team = $user->currentTeam;
        $teamId = $team->getKey();
        $teamMemberIds = $team->users()->pluck('users.id')->push($team->user_id)->unique()->all();

        return array_merge([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['string', Rule::in($teamMemberIds)],
        ], new ValidCustomFields($teamId, 'task', isUpdate: true)->toRules($this->input('custom_fields')));
    }
}
