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
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            return;
        }

        $existingTokenCount = PersonalAccessToken::query()
            ->where('tokenable_type', 'user')
            ->where('tokenable_id', $user->id)
            ->count();

        if ($existingTokenCount > 1) {
            return;
        }

        dispatch(new SyncSubscriberJob((string) $user->id))->afterCommit();
    }
}
