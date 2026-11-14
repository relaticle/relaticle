<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Jetstream\DeleteUser;
use App\Enums\SocialiteProvider;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

// A provider authoritative for an address nobody verified outranks whoever registered it. Deleting the
// row, not claiming it in place, drops every passkey, second factor and grant that registrant could leave.
final readonly class DeleteUnclaimedAccount
{
    public function __construct(private DeleteUser $deleteUser) {}

    public function execute(User $holder, SocialiteProvider $provider, SocialiteUser $claimant): bool
    {
        if (! $provider->isAuthoritativeForEmailOf($claimant)) {
            return false;
        }

        if (User::query()->whereKey($holder->getKey())->unclaimed()->doesntExist()) {
            return false;
        }

        $this->deleteUser->delete($holder);

        return true;
    }
}
