<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Concerns\ResolvesUpsertMatch;
use App\Models\Company;
use App\Rules\ValidCustomFields;
use Illuminate\Foundation\Http\FormRequest;

final class UpsertCompanyRequest extends FormRequest
{
    use ResolvesUpsertMatch;

    /**
     * A form usually supplies a company name rather than a custom field, so the
     * model's own `name` column is matchable alongside every custom field code.
     * The literal always means the column: a custom field sharing the code
     * cannot shadow it.
     *
     * @var array<int, string>
     */
    private const array NATIVE_MATCH_COLUMNS = ['name'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $teamId = $this->teamId();
        $company = $this->matchedCompany();

        // On the update branch a required custom field the caller omitted is
        // already answered by the matched record, and UpdateCompany merges it
        // back in — demanding it again would reject a legitimate partial upsert.
        return array_merge(
            $this->matchRules('company', self::NATIVE_MATCH_COLUMNS),
            ['name' => ['required', 'string', 'max:255']],
            new ValidCustomFields(
                $teamId,
                'company',
                isUpdate: $company instanceof Company,
                ignoreEntityId: $company?->getKey(),
            )->toRules($this->input('custom_fields')),
        );
    }

    public function matchedCompany(): ?Company
    {
        $matched = $this->resolveMatch(Company::class, self::NATIVE_MATCH_COLUMNS);

        return $matched instanceof Company ? $matched : null;
    }
}
