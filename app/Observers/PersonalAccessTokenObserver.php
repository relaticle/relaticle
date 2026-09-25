<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\PersonalAccessToken;
use App\Models\User;

final readonly class PersonalAccessTokenObserver
{
    public function created(PersonalAccessToken $token): void
    {
        // Guarded here too so the request path skips the exists-probe below.
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            return;
        }

        if ($user->tokens()->whereKeyNot($token->getKey())->exists()) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $user->id);
    }
}
