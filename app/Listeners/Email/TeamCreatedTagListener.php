<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Jetstream\Events\TeamCreated;

#[Backoff(15)]
#[Tries(10)]
final class TeamCreatedTagListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(TeamCreated $event): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var Team $team */
        $team = $event->team;

        $tags = [];

        if ($team->onboarding_use_case) {
            $tags[] = $team->onboarding_use_case->toSubscriberTag();
        }

        if ($team->onboarding_referral_source) {
            $tags[] = $team->onboarding_referral_source->toSubscriberTag();
        }

        if ($tags === []) {
            return;
        }

        /** @var User $owner */
        $owner = $team->owner()->first();

        if (! $owner->mailcoach_subscriber_uuid) {
            $this->release(15);

            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $owner->mailcoach_subscriber_uuid,
            $tags,
            TagAction::Add,
        ))->afterCommit();
    }
}
