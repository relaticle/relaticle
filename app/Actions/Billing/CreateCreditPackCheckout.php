<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use InvalidArgumentException;

final readonly class CreateCreditPackCheckout
{
    /**
     * Create a one-time Stripe Checkout session for a prepaid credit pack and
     * return its redirect URL. Stripe round-trip — covered by the staging E2E
     * checklist, not unit tests (same policy as CreateProCheckout).
     */
    public function execute(Team $team, string $pack): string
    {
        $priceId = $this->priceId($pack);

        $checkout = $team->checkout([$priceId => 1], $this->sessionOptions($team, $priceId));

        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    private function priceId(string $pack): string
    {
        // $pack arrives from the browser — pin it to the configured pack keys so
        // an arbitrary string never reaches a config lookup.
        $config = config("services.stripe.credit_packs.{$pack}");

        throw_unless(is_array($config), InvalidArgumentException::class, "Unknown credit pack [{$pack}].");

        $priceId = $config['price'] ?? null;

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for credit pack [{$pack}].");

        return $priceId;
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Team $team, string $priceId): array
    {
        $billingUrl = url("/app/{$team->slug}/billing");

        $options = [
            'success_url' => "{$billingUrl}?credits=success",
            'cancel_url' => $billingUrl,
            'metadata' => [
                'team_id' => (string) $team->getKey(),
                'credit_pack_price' => $priceId,
            ],
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
