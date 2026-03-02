<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCompany
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Company $company, array $data): Company
    {
        abort_unless($user->can('update', $company), 403);

        return DB::transaction(function () use ($company, $data): Company {
            $company->update($data);

            return $company->refresh();
        });
    }
}
