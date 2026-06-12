<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use InvalidArgumentException;
use Laravel\Cashier\Checkout;

final readonly class CreateProCheckout
{
    /**
     * Create the hosted Stripe Checkout session and return its redirect URL.
     * Stripe round-trip — covered by the staging E2E checklist, not unit tests.
     */
    public function handle(Team $team, string $interval): string
    {
        $checkout = $team
            ->newSubscription('default', $this->priceId($interval))
            ->allowPromotionCodes()
            ->checkout($this->sessionOptions($team));

        /** @var Checkout $checkout */
        return (string) $checkout->url;
    }

    public function priceId(string $interval): string
    {
        $priceId = config("services.stripe.prices.pro_{$interval}");

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for interval [{$interval}].");

        return $priceId;
    }

    /** @return array<string, mixed> */
    public function sessionOptions(Team $team): array
    {
        $billingUrl = url("/app/{$team->slug}/billing");

        $options = [
            'success_url' => "{$billingUrl}?checkout=success",
            'cancel_url' => $billingUrl,
            'allow_promotion_codes' => true,
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
