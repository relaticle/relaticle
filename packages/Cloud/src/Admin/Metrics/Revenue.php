<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\Cloud\Admin\Metrics\Scopes\CountsTowardMrr;
use Stripe\Coupon;
use Stripe\Discount;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\SubscriptionItem;
use Throwable;

final readonly class Revenue
{
    private const float DAYS_PER_MONTH = 365.25 / 12;

    public function monthlyMicros(?CarbonImmutable $asOf = null): ?int
    {
        $subscriptions = Subscription::query()
            ->withGlobalScope(CountsTowardMrr::class, new CountsTowardMrr($asOf))
            ->with('owner')
            ->get();

        $total = 0;

        foreach ($subscriptions as $subscription) {
            $terms = $this->terms($subscription);

            if ($terms === null) {
                return null;
            }

            if ($asOf instanceof CarbonImmutable && ! $this->wasPayingAt($terms, $asOf)) {
                continue;
            }

            $total += $terms['micros'];
        }

        return $total;
    }

    /**
     * @param  array{micros: int, invoice_paid: bool, invoice_created_at: ?int}  $terms
     */
    private function wasPayingAt(array $terms, CarbonImmutable $asOf): bool
    {
        return $terms['invoice_paid']
            || ($terms['invoice_created_at'] !== null && $terms['invoice_created_at'] > $asOf->getTimestamp());
    }

    /**
     * @return array{micros: int, invoice_paid: bool, invoice_created_at: ?int}|null
     */
    private function terms(Subscription $subscription): ?array
    {
        $key = "sysadmin.mrr.terms.{$subscription->stripe_id}.{$subscription->updated_at?->getTimestamp()}";

        /** @var array{terms: array{micros: int, invoice_paid: bool, invoice_created_at: ?int}|null}|null $cached */
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached['terms'];
        }

        try {
            $terms = $this->fetchTerms($subscription);
        } catch (Throwable $exception) {
            report($exception);

            $terms = $this->isUnknownToStripe($exception) ? ['micros' => 0, 'invoice_paid' => false, 'invoice_created_at' => null] : null;
        }

        Cache::put($key, ['terms' => $terms], $terms === null ? now()->addMinutes(10) : now()->addDay());

        return $terms;
    }

    private function isUnknownToStripe(Throwable $exception): bool
    {
        return $exception instanceof InvalidRequestException
            && $exception->getHttpStatus() === 404
            && $exception->getStripeCode() === 'resource_missing';
    }

    /**
     * @return array{micros: int, invoice_paid: bool, invoice_created_at: ?int}|null
     */
    private function fetchTerms(Subscription $subscription): ?array
    {
        $stripe = $subscription->asStripeSubscription(['latest_invoice', 'discounts.source.coupon', 'items.data.discounts']);
        $invoice = $stripe->latest_invoice;

        if (is_string($invoice)) {
            return null;
        }

        $micros = 0.0;
        $billingMonths = null;

        foreach ($stripe->items->data as $item) {
            $months = $this->months($item->price);
            $itemMicros = $months === null ? null : $this->itemMicros($item, $months);

            if ($months === null || $itemMicros === null) {
                return null;
            }

            $billingMonths ??= $months;
            $discounted = $this->discounted($itemMicros, $months, $item->discounts ?? []);

            if ($discounted === null) {
                return null;
            }

            $micros += $discounted;
        }

        $micros = $this->discounted($micros, $billingMonths ?? 1.0, $stripe->discounts ?? []);

        if ($micros === null) {
            return null;
        }

        return [
            'micros' => max(0, (int) round($micros)),
            'invoice_paid' => $invoice instanceof Invoice && $invoice->status === Invoice::STATUS_PAID,
            'invoice_created_at' => $invoice instanceof Invoice ? $invoice->created : null,
        ];
    }

    private function itemMicros(SubscriptionItem $item, float $months): ?float
    {
        $price = $item->price;

        if ($price->unit_amount === null || $price->tax_behavior === Price::TAX_BEHAVIOR_INCLUSIVE) {
            return null;
        }

        return $price->unit_amount * ($item->quantity ?? 1) * 10_000 / $months;
    }

    private function months(Price $price): ?float
    {
        $recurring = $price->recurring;

        if ($recurring === null || $recurring->usage_type !== 'licensed') {
            return null;
        }

        $count = max(1, (int) $recurring->interval_count);

        return match ($recurring->interval) {
            'year' => 12.0 * $count,
            'month' => (float) $count,
            'week' => 7 * $count / self::DAYS_PER_MONTH,
            'day' => $count / self::DAYS_PER_MONTH,
            default => null,
        };
    }

    /**
     * @param  array<int, Discount|string>  $discounts
     */
    private function discounted(float $micros, float $billingMonths, array $discounts): ?float
    {
        foreach ($discounts as $discount) {
            if (! $discount instanceof Discount) {
                return null;
            }

            $coupon = $this->coupon($discount);

            if (! $coupon instanceof Coupon) {
                return null;
            }

            if ($coupon->duration === Coupon::DURATION_ONCE || ($discount->end !== null && $discount->end <= now()->getTimestamp())) {
                continue;
            }

            $micros = $coupon->percent_off !== null
                ? $micros * (1 - $coupon->percent_off / 100)
                : $micros - ($coupon->amount_off ?? 0) * 10_000 / $billingMonths;
        }

        return max(0.0, $micros);
    }

    private function coupon(Discount $discount): ?Coupon
    {
        $coupon = $discount->source->coupon ?? null;

        if (is_string($coupon)) {
            return Cashier::stripe()->coupons->retrieve($coupon);
        }

        return $coupon instanceof Coupon ? $coupon : null;
    }
}
