<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\AiSummary;
use App\Models\User;
use App\Observers\Concerns\TagsFirstCrmData;
use App\Support\Email\SubscriberProfileDeriver;

/**
 * Syncs the authenticated user's Mailcoach profile when the first AI summary is
 * generated in one of their workspaces, so has-ai-usage lands immediately
 * instead of waiting for the nightly reconcile sweep.
 *
 * Mirrors {@see TagsFirstCrmData}: the fast path only covers interactive
 * sessions, and everything else converges through the sweep.
 */
final readonly class AiSummaryObserver
{
    public function created(AiSummary $summary): void
    {
        // Guarded here too so the request path skips the exists-probes below.
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if (resolve(SubscriberProfileDeriver::class)->hasAiUsage($user, $summary)) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $user->id);
    }
}
