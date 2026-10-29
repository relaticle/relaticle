<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Arr;
use Laravel\Cashier\Subscription;

enum Plan: string implements HasColor, HasLabel
{
    case Free = 'free';
    case Pro = 'pro';
    case Concierge = 'concierge';
    case Enterprise = 'enterprise';

    public static function default(): self
    {
        return self::Free;
    }

    /**
     * Resolve the plan a Stripe price id belongs to, using the price map in
     * `config('services.stripe.prices')` where each key is `<plan>_<interval>`.
     */
    public static function fromStripePrice(?string $priceId): ?self
    {
        $key = self::stripePriceKey($priceId);

        return $key === null ? null : self::tryFrom(explode('_', $key)[0]);
    }

    public static function fromStripeProduct(?string $productId): ?self
    {
        if ($productId === null) {
            return null;
        }

        return Arr::first(self::cases(), fn (self $plan): bool => $plan->stripeProduct() === $productId);
    }

    public static function soldBy(Subscription $subscription): ?self
    {
        return Arr::first(self::cases(), fn (self $plan): bool => $plan->stripeProduct() !== null && $subscription->hasProduct($plan->stripeProduct()));
    }

    /**
     * The human label for a Stripe price id, `Pro · monthly`. Falls back to the
     * id itself when it is not in the price map, so an unmapped price is still
     * identifiable on screen.
     */
    public static function stripePriceLabel(?string $priceId): string
    {
        $plan = self::fromStripePrice($priceId);

        if (! $plan instanceof self) {
            return $priceId ?? '—';
        }

        $interval = self::intervalFromStripePrice($priceId);

        return $interval === null ? $plan->getLabel() : "{$plan->getLabel()} · {$interval}";
    }

    /**
     * The billing interval a Stripe price id sells the plan at (`monthly`,
     * `yearly`), read from the same map. Null when the id is not in it.
     */
    private static function intervalFromStripePrice(?string $priceId): ?string
    {
        $key = self::stripePriceKey($priceId);

        return $key === null ? null : explode('_', $key, 2)[1] ?? null;
    }

    /**
     * The `<plan>_<interval>` key a Stripe price id sits under in
     * `config('services.stripe.prices')`.
     *
     * The single place the map is walked: a display helper that re-listed
     * `pro_monthly` and `pro_yearly` by hand went stale the moment a price was
     * added, and showed the raw `price_...` id instead.
     */
    private static function stripePriceKey(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        /** @var array<string, string|null> $prices */
        $prices = config('services.stripe.prices', []);

        foreach ($prices as $key => $mappedPriceId) {
            if ($mappedPriceId !== null && $mappedPriceId === $priceId) {
                return $key;
            }
        }

        return null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Concierge => 'Concierge',
            self::Enterprise => 'Enterprise',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Free => 'gray',
            self::Pro => 'success',
            self::Concierge => 'info',
            self::Enterprise => 'primary',
        };
    }

    public function marketingLabel(): string
    {
        return match ($this) {
            self::Free => __('billing.plans.free'),
            self::Pro => __('billing.plans.cloud_pro'),
            self::Concierge => __('billing.plans.cloud_concierge'),
            self::Enterprise => __('billing.plans.enterprise'),
        };
    }

    public function credits(): int
    {
        return match ($this) {
            self::Free => 300,
            self::Pro => 2_000,
            self::Concierge => 5_000,
            self::Enterprise => 10_000,
        };
    }

    public function rateLimit(): int
    {
        return match ($this) {
            self::Free => 10,
            self::Pro => 30,
            self::Concierge => 30,
            self::Enterprise => 60,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1,
            self::Concierge => 2,
            self::Enterprise => 3,
        };
    }

    // Granted by hand and invoiced outside Stripe Managed Payments, which cannot sell a
    // plan that includes human service, so no Stripe subscription may replace one.
    public function isManaged(): bool
    {
        return match ($this) {
            self::Free, self::Pro => false,
            self::Concierge, self::Enterprise => true,
        };
    }

    public function stripeProduct(): ?string
    {
        return match ($this) {
            self::Free, self::Pro => null,
            self::Concierge => config('services.stripe.products.concierge'),
            self::Enterprise => config('services.stripe.products.enterprise'),
        };
    }

    public function defaultYearlyPrice(): ?int
    {
        return match ($this) {
            self::Free, self::Pro => null,
            self::Concierge => config()->integer('relaticle.concierge.price_yearly'),
            self::Enterprise => config()->integer('relaticle.enterprise.starting_price_yearly'),
        };
    }
}
