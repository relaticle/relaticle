<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Team;
use App\Models\User;
use Laravel\Jetstream\Events\TeamMemberAdded;

final class TeamMemberAddedListener
{
    /**
     * The owner gains has-team-members; the new member may gain has-crm-data
     * through the team they just joined. Both profiles are re-synced.
     */
    public function handle(TeamMemberAdded $event): void
    {
        /** @var Team $team */
        $team = $event->team;

        /** @var User $member */
        $member = $event->user;

        dispatch(new SyncSubscriberJob((string) $team->user_id))->afterCommit();
        dispatch(new SyncSubscriberJob((string) $member->id))->afterCommit();
    }
}
