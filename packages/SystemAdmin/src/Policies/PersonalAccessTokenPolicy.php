<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use App\Models\PersonalAccessToken;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class PersonalAccessTokenPolicy
{
    public function viewAny(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }

    public function view(SystemAdministrator $admin, PersonalAccessToken $token): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator
            && $this->ownsToken($admin, $token);
    }

    public function create(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }

    public function delete(SystemAdministrator $admin, PersonalAccessToken $token): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator
            && $this->ownsToken($admin, $token);
    }

    /**
     * `create()` cannot see which administrator record the token would be minted
     * for (Filament resolves it before the model exists), so it stays role-only —
     * the relation manager's mint action enforces self-only ownership directly.
     */
    private function ownsToken(SystemAdministrator $admin, PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === $admin->getMorphClass()
            && (string) $token->tokenable_id === (string) $admin->getKey();
    }
}
