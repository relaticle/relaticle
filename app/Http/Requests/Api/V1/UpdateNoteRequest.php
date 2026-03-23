<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateNoteRequest extends FormRequest
{
    use ValidatesCustomFields;

    public function authorize(): bool
    {
        return true;
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', Rule::exists('people', 'id')->where('team_id', $teamId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', Rule::exists('opportunities', 'id')->where('team_id', $teamId)],
            'custom_fields' => ['nullable', 'array'],
        ], $this->customFieldRules());
    }

    public function customFieldEntityType(): string
    {
        return 'note';
    }
}
