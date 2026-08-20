<?php

declare(strict_types=1);

namespace App\Enums;

enum Plan: string
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
        if ($priceId === null) {
            return null;
        }

        /** @var array<string, string|null> $prices */
        $prices = config('services.stripe.prices', []);

        foreach ($prices as $key => $mappedPriceId) {
            if ($mappedPriceId !== null && $mappedPriceId === $priceId) {
                return self::tryFrom(explode('_', $key)[0]);
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Enterprise => 'Enterprise',
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
