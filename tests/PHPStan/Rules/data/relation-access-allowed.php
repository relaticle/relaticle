<?php

declare(strict_types=1);

namespace App\Services\Fixture {
    use App\Models\Company;

    (new Company)->team?->getKey();
}

namespace App\Policies {
    use App\Models\Company;
    use App\Models\User;

    final class RelationAccessAllowedFixture
    {
        public function viewAny(User $user): bool
        {
            return $user->currentTeam !== null && $user->teams->isNotEmpty();
        }

        public function view(User $user, Company $company): bool
        {
            return $company->name !== '' && $user->belongsToTeamId($company->team_id);
        }
    }
}
