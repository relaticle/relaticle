<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Establish a brand-new provider association for an already-authenticated
 * user. Both proofs must already be settled before this runs: the caller's
 * fresh account confirmation gated entry to the OAuth redirect, and the just
 * completed OAuth round trip proved the provider identity, bound to the
 * operation grant this consumes.
 */
final readonly class LinkSocialAccount
{
    public function execute(User $user, SocialiteProvider $provider, string $providerId): void
    {
        DB::transaction(function () use ($user, $provider, $providerId): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser instanceof User) {
                throw ValidationException::withMessages([
                    'identity' => [__('auth.confirm.required')],
                ]);
            }

            $existing = UserSocialAccount::query()
                ->where('provider_name', $provider->value)
                ->where('provider_id', $providerId)
                ->first();

            if ($existing instanceof UserSocialAccount && $existing->user_id !== $lockedUser->getKey()) {
                throw $this->alreadyLinked($provider);
            }

            // One row per provider per user: ConfirmIdentity assumes exactly one.
            if ($lockedUser->socialAccounts()->where('provider_name', $provider->value)->exists()) {
                throw $this->alreadyLinked($provider);
            }

            AuthenticationSession::consumeOperation($lockedUser, 'link_provider', $provider->value.':'.$providerId);

            try {
                $lockedUser->socialAccounts()->create([
                    'provider_name' => $provider->value,
                    'provider_id' => $providerId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->alreadyLinked($provider);
            }
        });
    }

    private function alreadyLinked(SocialiteProvider $provider): ValidationException
    {
        return ValidationException::withMessages([
            'identity' => [__('auth.link.already_linked', ['provider' => ucfirst($provider->value)])],
        ]);
    }
}
