<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriberTagEnum;
use App\Jobs\Email\SyncRecencyBucketJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class SyncSubscriberRecencyTagsCommand extends Command
{
    protected $signature = 'subscribers:sync-recency-tags';

    protected $description = 'Sync time-decay recency tags to Mailcoach for users whose bucket changed';

    public function handle(): int
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            $this->info('Mailcoach subscriber sync is disabled.');

            return self::SUCCESS;
        }

        $synced = 0;

        User::query()
            ->whereNotNull('mailcoach_subscriber_uuid')
            ->select(['id', 'last_login_at', 'mailcoach_subscriber_uuid', 'subscriber_recency_bucket'])
            ->chunkById(200, function (Collection $users) use (&$synced): void {
                /** @var User $user */
                foreach ($users as $user) {
                    $newBucket = $this->computeBucket($user);
                    $oldBucket = $user->subscriber_recency_bucket;

                    if ($newBucket === $oldBucket) {
                        continue;
                    }

                    dispatch(new SyncRecencyBucketJob(
                        (string) $user->id,
                        (string) $user->mailcoach_subscriber_uuid,
                        $oldBucket,
                        $newBucket,
                    ));
                    $synced++;
                }
            });

        $this->info("Synced recency tags for {$synced} users.");

        return self::SUCCESS;
    }

    private function computeBucket(User $user): ?string
    {
        if ($user->last_login_at === null) {
            return null;
        }

        $daysSinceLogin = (int) abs($user->last_login_at->diffInDays(now()));

        return match (true) {
            $daysSinceLogin <= 7 => SubscriberTagEnum::Active7d->value,
            $daysSinceLogin <= 30 => SubscriberTagEnum::Active30d->value,
            $daysSinceLogin > 60 => SubscriberTagEnum::Dormant->value,
            default => null, // 31-60 days: transition window, no tag
        };
    }
}
