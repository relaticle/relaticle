<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateCompany
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Company
    {
        abort_unless($user->can('create', Company::class), 403);

        TenantFkValidator::assertUserInWorkspace($user, $data, ['account_owner_id']);

        $attributes = Arr::only($data, ['name', 'account_owner_id', 'custom_fields']);

        $company = DB::transaction(fn (): Company => Company::query()->create($attributes));

        return $company->load('customFieldValues.customField.options');
    }
}
