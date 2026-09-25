<?php

declare(strict_types=1);

namespace App\Observers\Concerns;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use App\Support\Email\SubscriberProfileDeriver;
use Illuminate\Database\Eloquent\Model;

/**
 * Syncs the authenticated user's Mailcoach profile when the first CRM entity
 * (Company, People, or Opportunity) is created, so has-crm-data lands
 * immediately instead of waiting for the nightly reconcile sweep.
 *
 * Intentionally relies on auth()->user(): the fast path only covers
 * interactive sessions. Entities created via queue workers, console commands,
 * or seeders are picked up by the sweep instead.
 */
trait TagsFirstCrmData
{
    protected function tagFirstCrmDataIfNeeded(Model $createdModel): void
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

        if (resolve(SubscriberProfileDeriver::class)->hasCrmData($user, $createdModel)) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $user->id);
    }
}
