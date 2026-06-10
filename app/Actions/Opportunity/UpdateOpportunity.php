<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Support\CustomFieldMerger;
use App\Support\TenantFkValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdateOpportunity
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Opportunity $opportunity, array $data): Opportunity
    {
        abort_unless($user->can('update', $opportunity), 403);

        TenantFkValidator::assertOwned($user, $data, [
            'company_id' => Company::class,
            'contact_id' => People::class,
        ]);

        $attributes = Arr::only($data, ['name', 'company_id', 'contact_id', 'custom_fields']);

        $attributes = CustomFieldMerger::merge($opportunity, $attributes);

        return DB::transaction(function () use ($opportunity, $attributes): Opportunity {
            $opportunity->update($attributes);

            return $opportunity->refresh()->load('customFieldValues.customField.options');
        });
    }
}
