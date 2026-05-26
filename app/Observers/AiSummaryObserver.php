<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\AiSummary;
use App\Models\User;

final readonly class AiSummaryObserver
{
    public function created(AiSummary $summary): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User || ! $user->mailcoach_subscriber_uuid) {
            return;
        }

        $teamIds = $user->allTeams()->pluck('id');
        $alreadyHadSummary = AiSummary::query()
            ->whereIn('team_id', $teamIds)
            ->whereKeyNot($summary->getKey())
            ->exists();

        if ($alreadyHadSummary) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $user->mailcoach_subscriber_uuid,
            [SubscriberTagEnum::HasAiUsage->value],
            TagAction::Add,
        ))->afterCommit();
    }
}
