<?php

declare(strict_types=1);

namespace App\Observers\Concerns;

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Tags the authenticated user's Mailcoach subscriber with "has-crm-data" when
 * the first CRM entity (Company, People, or Opportunity) is created.
 *
 * Intentionally relies on auth()->user() — tagging only applies to interactive
 * sessions. Entities created via queue workers, console commands, or seeders
 * are excluded by design.
 */
trait TagsFirstCrmData
{
    protected function tagFirstCrmDataIfNeeded(Model $createdModel): void
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

        $hasCrmData = Company::query()->whereIn('team_id', $teamIds)
            ->when($createdModel instanceof Company, fn ($q) => $q->whereKeyNot($createdModel->getKey()))
            ->exists()
            || People::query()->whereIn('team_id', $teamIds)
                ->when($createdModel instanceof People, fn ($q) => $q->whereKeyNot($createdModel->getKey()))
                ->exists()
            || Opportunity::query()->whereIn('team_id', $teamIds)
                ->when($createdModel instanceof Opportunity, fn ($q) => $q->whereKeyNot($createdModel->getKey()))
                ->exists();

        if ($hasCrmData) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $user->mailcoach_subscriber_uuid,
            [SubscriberTagEnum::HasCrmData->value],
            TagAction::Add,
        ))->afterCommit();
    }
}
