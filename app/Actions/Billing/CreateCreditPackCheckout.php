<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Filament\Pages\Billing;
use App\Models\Team;
use App\Services\Billing\CreditPackCatalog;
use InvalidArgumentException;

final readonly class CreateCreditPackCheckout
{
    public function __construct(private CreditPackCatalog $catalog) {}

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
        // $pack arrives from the browser — pin it to the purchasable pack keys
        // so neither an arbitrary string nor a pack without a configured Stripe
        // price ever reaches checkout.
        $config = $this->catalog->find($pack);

        throw_unless(is_array($config), InvalidArgumentException::class, "Unknown or unpurchasable credit pack [{$pack}].");

        return $config['price'];
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Team $team, string $priceId): array
    {
        $billingUrl = Billing::getUrl(panel: 'app', tenant: $team);

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
