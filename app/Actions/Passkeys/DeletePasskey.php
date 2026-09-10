<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Enums\SocialiteProvider;
use App\Features\SocialAuth;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey as VendorDeletePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Pennant\Feature;

final class DeletePasskey extends VendorDeletePasskey
{
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        abort_unless($user instanceof User, 403);

        DB::transaction(function () use ($user, $passkey): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($passkey->user_id === $lockedUser->getKey(), 403);

            $ownedPasskey = $lockedUser->passkeys()->whereKey($passkey->getKey())->firstOrFail();
            $hasAlternative = $lockedUser->hasPassword()
                || $lockedUser->passkeys()->whereKeyNot($ownedPasskey->getKey())->exists()
                || (Feature::for($lockedUser)->active(SocialAuth::class)
                    && $lockedUser->socialAccounts()->whereIn('provider_name', SocialiteProvider::cases())->exists());

            if (! $hasAlternative) {
                throw ValidationException::withMessages(['identity' => __('auth.link.last_method')]);
            }

            parent::__invoke($lockedUser, $ownedPasskey);
        });
    }
}
