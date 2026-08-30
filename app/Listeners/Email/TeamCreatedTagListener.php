<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Team;
use Laravel\Jetstream\Events\TeamCreated;

final class TeamCreatedTagListener
{
    public function handle(TeamCreated $event): void
    {
        /** @var Team $team */
        $team = $event->team;

        if ($team->onboardingSubscriberTags() === []) {
            return;
        }

        dispatch(new SyncSubscriberJob((string) $team->user_id))->afterCommit();
    }
}
