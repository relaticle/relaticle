<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Concerns\ResolvesUpsertMatch;
use App\Models\People;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertPeopleRequest extends FormRequest
{
    use ResolvesUpsertMatch;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $teamId = $this->teamId();
        $person = $this->matchedPerson();

        // On the update branch a required custom field the caller omitted is
        // already answered by the matched record, and UpdatePeople merges it
        // back in — demanding it again would reject a legitimate partial upsert.
        return array_merge(
            $this->matchRules('people'),
            [
                'name' => ['required', 'string', 'max:255'],
                'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            ],
            new ValidCustomFields(
                $teamId,
                'people',
                isUpdate: $person instanceof People,
                ignoreEntityId: $person?->getKey(),
            )->toRules($this->input('custom_fields')),
        );
    }

    public function matchedPerson(): ?People
    {
        $matched = $this->resolveMatch(People::class);

        return $matched instanceof People ? $matched : null;
    }
}
