<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Team;
use App\Models\User;
use Laravel\Jetstream\Events\TeamMemberAdded;

final class TeamMemberAddedListener
{
    public function handle(TeamMemberAdded $event): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var Team $team */
        $team = $event->team;

        /** @var User $owner */
        $owner = $team->owner;

        if (! $owner->mailcoach_subscriber_uuid) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $owner->mailcoach_subscriber_uuid,
            [SubscriberTagEnum::HasTeamMembers->value],
            TagAction::Add,
        ))->afterCommit();
    }
}
