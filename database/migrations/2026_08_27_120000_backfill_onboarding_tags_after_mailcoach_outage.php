<?php

declare(strict_types=1);

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * relaticle.mailcoach.app served an expired TLS certificate on 2026-08-26.
     * SyncSubscriberJob could not store a subscriber uuid for the duration, and
     * TeamCreatedTagListener gave up polling for one after 150 seconds, so teams
     * created inside the outage never received their onboarding tags.
     *
     * Mailcoach's POST /tags is additive, so re-dispatching is harmless for any
     * team in the window that was tagged successfully anyway.
     */
    public function up(): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        Team::query()
            ->whereBetween('created_at', ['2026-08-26 02:00:00', '2026-08-26 07:00:00'])
            ->select(['id', 'user_id', 'onboarding_use_case', 'onboarding_referral_source'])
            ->chunkById(200, function (Collection $teams): void {
                /** @var Team $team */
                foreach ($teams as $team) {
                    $tags = $team->onboardingSubscriberTags();

                    if ($tags === []) {
                        continue;
                    }

                    dispatch(new ModifySubscriberTagsJob(
                        (string) $team->user_id,
                        $tags,
                        TagAction::Add,
                    ));
                }
            });
    }
};
