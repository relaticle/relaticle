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

        // Every registration creates a bare personal team; the Verified
        // listener owns that initial sync, so only onboarding answers matter.
        if ($team->onboardingSubscriberTags() === []) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $team->user_id);
    }
}
