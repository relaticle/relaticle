<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Plan: string implements HasColor, HasLabel
{
    case Free = 'free';
    case Pro = 'pro';
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
            self::Enterprise => 'Enterprise',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Free => 'gray',
            self::Pro => 'success',
            self::Enterprise => 'primary',
        };
    }

    public function credits(): int
    {
        return match ($this) {
            self::Free => 300,
            self::Pro => 2_000,
            self::Enterprise => 10_000,
        };
    }

    public function rateLimit(): int
    {
        return match ($this) {
            self::Free => 10,
            self::Pro => 30,
            self::Enterprise => 60,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1,
            self::Enterprise => 2,
        };
    }
}
