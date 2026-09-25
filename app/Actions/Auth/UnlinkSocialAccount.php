<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove a provider association. Locks the user row so a concurrent removal
 * of a different method cannot leave the account with zero ways to sign in;
 * both checks and the eventual delete happen against the same locked count.
 */
final readonly class UnlinkSocialAccount
{
    public function execute(User $user, UserSocialAccount $account): void
    {
        DB::transaction(function () use ($user, $account): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            abort_unless($lockedUser instanceof User, 403);
            abort_unless($account->user_id === $lockedUser->getKey(), 403);

            $remainingMethods = ($lockedUser->hasPassword() ? 1 : 0)
                + ($lockedUser->hasPasskey() ? 1 : 0)
                + $lockedUser->socialAccounts()->whereKeyNot($account->getKey())->count();

            if ($remainingMethods < 1) {
                throw ValidationException::withMessages([
                    'identity' => [__('auth.link.last_method')],
                ]);
            }

            AuthenticationSession::consumeOperation($lockedUser, 'unlink_provider', (string) $account->getKey());

            $account->delete();
        });
    }
}
