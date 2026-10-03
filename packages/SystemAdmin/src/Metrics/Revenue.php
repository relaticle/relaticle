<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Metrics\Scopes\CountsTowardMrr;
use Stripe\Exception\InvalidRequestException;
use Throwable;

final readonly class Revenue
{
    public function monthlyMicros(?CarbonImmutable $asOf = null): ?int
    {
        $subscriptions = Subscription::query()
            ->withGlobalScope(CountsTowardMrr::class, new CountsTowardMrr($asOf))
            ->with('owner')
            ->get();

        $total = 0;

        foreach ($subscriptions as $subscription) {
            $amount = $this->monthlyAmountMicros($subscription);

            if ($amount === null) {
                return null;
            }

            $total += $amount;
        }

        return $total;
    }

    private function monthlyAmountMicros(Subscription $subscription): ?int
    {
        $key = "sysadmin.mrr.{$subscription->stripe_id}";

        /** @var array{micros: ?int}|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached['micros'];
        }

        try {
            $micros = $this->fetchMonthlyAmountMicros($subscription);
        } catch (Throwable $exception) {
            report($exception);

            $micros = $this->isUnknownToStripe($exception) ? 0 : null;
        }

        Cache::put($key, ['micros' => $micros], $micros === null ? now()->addMinutes(10) : now()->addDay());

        return $micros;
    }

    private function isUnknownToStripe(Throwable $exception): bool
    {
        return $exception instanceof InvalidRequestException
            && $exception->getHttpStatus() === 404
            && $exception->getStripeCode() === 'resource_missing';
    }

    private function fetchMonthlyAmountMicros(Subscription $subscription): ?int
    {
        $stripe = $subscription->asStripeSubscription(['latest_invoice']);
        $invoice = $stripe->latest_invoice;

        if ($invoice === null) {
            return 0;
        }

        if (is_string($invoice)) {
            return null;
        }

        $recurring = $stripe->items->data[0]->price->recurring ?? null;
        $count = max(1, (int) ($recurring->interval_count ?? 1));
        $months = match ($recurring->interval ?? 'month') {
            'year' => 12 * $count,
            default => $count,
        };

        return max(0, intdiv(((int) $invoice->total_excluding_tax) * 10_000, $months));
    }
}
