<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The prepaid credit packs that can actually be bought right now.
 *
 * A pack whose Stripe price is not configured is not purchasable, so it must
 * not be offered anywhere — otherwise "Add credits" and "Buy more credits"
 * lead to a billing page with nothing to buy.
 */
final readonly class CreditPackCatalog
{
    /** @return array<string, array{price: string, credits: int}> */
    public function purchasable(): array
    {
        /** @var array<string, mixed> $packs */
        $packs = config('services.stripe.credit_packs', []);

        $purchasable = [];

        foreach ($packs as $key => $pack) {
            if (! is_array($pack)) {
                continue;
            }

            $price = $pack['price'] ?? null;
            $credits = $pack['credits'] ?? null;
            if (! is_string($price)) {
                continue;
            }
            if ($price === '') {
                continue;
            }
            if (! is_int($credits)) {
                continue;
            }
            if ($credits < 1) {
                continue;
            }

            $purchasable[(string) $key] = ['price' => $price, 'credits' => $credits];
        }

        return $purchasable;
    }

    public function hasPurchasable(): bool
    {
        return $this->purchasable() !== [];
    }

    /** @return array{price: string, credits: int}|null */
    public function find(string $pack): ?array
    {
        return $this->purchasable()[$pack] ?? null;
    }

    public function creditsForPrice(string $priceId): ?int
    {
        foreach ($this->purchasable() as $pack) {
            if ($pack['price'] === $priceId) {
                return $pack['credits'];
            }
        }

        return null;
    }
}
