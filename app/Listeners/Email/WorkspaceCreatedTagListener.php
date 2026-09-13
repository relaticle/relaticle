<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Events\WorkspaceCreated;
use App\Jobs\Email\SyncSubscriberJob;

final class WorkspaceCreatedTagListener
{
    public function handle(WorkspaceCreated $event): void
    {
        $workspace = $event->workspace;

        // Every registration creates a bare personal workspace; the Verified
        // listener owns that initial sync, so only onboarding answers matter.
        if ($workspace->onboardingSubscriberTags() === []) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $workspace->user_id);
    }
}
