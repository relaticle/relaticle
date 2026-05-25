<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
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

        if (! $user instanceof User || ! $user->mailcoach_subscriber_uuid) {
            return;
        }

        $existingTokenCount = PersonalAccessToken::query()
            ->where('tokenable_type', 'user')
            ->where('tokenable_id', $user->id)
            ->count();

        if ($existingTokenCount > 1) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $user->mailcoach_subscriber_uuid,
            [SubscriberTagEnum::HasApiToken->value],
            TagAction::Add,
        ))->afterCommit();
    }
}
