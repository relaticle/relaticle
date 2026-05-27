<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateNoteRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
        ], new ValidCustomFields($teamId, 'note', isUpdate: true)->toRules($this->input('custom_fields')));
    }
}
