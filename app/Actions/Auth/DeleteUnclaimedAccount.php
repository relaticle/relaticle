<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Jetstream\DeleteUser;
use App\Models\User;

// A provider that vouches for an address nobody verified outranks whoever registered it. Deleting the
// row, not claiming it in place, drops every passkey, second factor and grant that registrant could leave.
final readonly class DeleteUnclaimedAccount
{
    public function __construct(private DeleteUser $deleteUser) {}

    public function execute(User $holder): bool
    {
        if ($holder->hasVerifiedEmail()) {
            return false;
        }

        if ($holder->ownedWorkspaces()->exists()) {
            return false;
        }

        if ($holder->workspaces()->exists()) {
            return false;
        }

        if ($holder->socialAccounts()->exists()) {
            return false;
        }

        $this->deleteUser->delete($holder);

        return true;
    }
}
