<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Support\CustomFieldMerger;
use App\Support\TenantFkValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePeople
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, People $people, array $data): People
    {
        abort_unless($user->can('update', $people), 403);

        TenantFkValidator::assertOwned($user, $data, [
            'company_id' => Company::class,
        ]);

        $attributes = Arr::only($data, ['name', 'company_id', 'custom_fields']);

        $attributes = CustomFieldMerger::merge($people, $attributes);

        return DB::transaction(function () use ($people, $attributes): People {
            $people->update($attributes);

            return $people->refresh()->load('customFieldValues.customField.options');
        });
    }
}
