<?php

declare(strict_types=1);

namespace App\Services\Fixture {
    use App\Models\Company;

    (new Company)->workspace?->getKey();
}

namespace App\Policies {
    use App\Models\Company;
    use App\Models\User;

    final class RelationAccessAllowedFixture
    {
        public function viewAny(User $user): bool
        {
            return $user->currentWorkspace !== null && $user->workspaces->isNotEmpty();
        }

        public function view(User $user, Company $company): bool
        {
            return $company->name !== '' && $user->belongsToWorkspaceId($company->workspace_id);
        }
    }
}
