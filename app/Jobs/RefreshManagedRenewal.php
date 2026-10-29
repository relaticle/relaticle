<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\StripeSubscriptionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;

#[Tries(3)]
#[Backoff([10, 60])]
#[UniqueFor(300)]
final class RefreshManagedRenewal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const string CACHE_PREFIX = 'managed-renewal:';

    public function __construct(public readonly string $stripeSubscriptionId) {}

    public function uniqueId(): string
    {
        return $this->stripeSubscriptionId;
    }

    public function handle(): void
    {
        $key = self::CACHE_PREFIX.$this->stripeSubscriptionId;
        $subscription = Cashier::stripe()->subscriptions->retrieve($this->stripeSubscriptionId);
        $renewsAt = $subscription->items->data[0]->current_period_end ?? null;

        if (! is_int($renewsAt) || ! in_array($subscription->status, StripeSubscriptionStatus::live(), true)) {
            Cache::forget($key);

            return;
        }

        $upcoming = Cashier::stripe()->invoices->createPreview(['subscription' => $this->stripeSubscriptionId]);

        Cache::forever($key, [
            'renews_at' => $renewsAt,
            'amount' => (int) $upcoming->total,
            'currency' => (string) $upcoming->currency,
        ]);
    }
}
