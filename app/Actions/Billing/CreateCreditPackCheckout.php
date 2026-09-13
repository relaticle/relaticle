<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Filament\Pages\Billing;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use InvalidArgumentException;

final readonly class CreateCreditPackCheckout
{
    public function __construct(private CreditPackCatalog $catalog) {}

    /**
     * Create a one-time Stripe Checkout session for a prepaid credit pack and
     * return its redirect URL. Stripe round-trip, covered by the staging E2E
     * checklist, not unit tests (same policy as CreateProCheckout).
     */
    public function execute(Workspace $workspace, string $pack): string
    {
        $priceId = $this->priceId($pack);

        $checkout = $workspace->checkout([$priceId => 1], $this->sessionOptions($workspace, $priceId));

        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    private function priceId(string $pack): string
    {
        // $pack arrives from the browser, so pin it to the purchasable pack keys
        // so neither an arbitrary string nor a pack without a configured Stripe
        // price ever reaches checkout.
        $config = $this->catalog->find($pack);

        throw_unless(is_array($config), InvalidArgumentException::class, "Unknown or unpurchasable credit pack [{$pack}].");

        return $config['price'];
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Workspace $workspace, string $priceId): array
    {
        $billingUrl = Billing::getUrl(panel: 'app', tenant: $workspace);

        $options = [
            'success_url' => "{$billingUrl}?credits=success",
            'cancel_url' => $billingUrl,
            // Stripe stores this key on its own side, and the webhook reads it
            // back off sessions created before any deploy. It cannot be renamed.
            'metadata' => [
                'team_id' => (string) $workspace->getKey(),
                'credit_pack_price' => $priceId,
            ],
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
