<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;

final readonly class DeleteCompany
{
    public function execute(User $user, Company $company): void
    {
        abort_unless($user->can('delete', $company), 403);

        $company->delete();
    }
}
