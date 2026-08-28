<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Laravel\Jetstream\Events\TeamCreated;

#[Backoff(15)]
#[Tries(10)]
final class TeamCreatedTagListener implements ShouldQueue
{
    public function handle(TeamCreated $event): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var Team $team */
        $team = $event->team;

        $tags = $team->onboardingSubscriberTags();

        if ($tags === []) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            (string) $team->user_id,
            $tags,
            TagAction::Add,
        ))->afterCommit();
    }
}
