<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Jetstream\Events\TeamMemberAdded;

final class WorkspaceMemberAddedListener
{
    /**
     * The owner gains has-workspace-members; the new member may gain has-crm-data
     * through the workspace they just joined. Both profiles are re-synced.
     */
    public function handle(TeamMemberAdded $event): void
    {
        /** @var Workspace $workspace */
        $workspace = $event->team;

        /** @var User $member */
        $member = $event->user;

        SyncSubscriberJob::dispatchFor((string) $workspace->user_id);
        SyncSubscriberJob::dispatchFor((string) $member->id);
    }
}
