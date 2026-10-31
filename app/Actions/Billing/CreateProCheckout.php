<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Entitlements;
use App\Filament\Pages\Billing;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CreateProCheckout
{
    /** @var list<string> */
    public const array INTERVALS = ['monthly', 'yearly'];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * Create the hosted Stripe Checkout session and return its redirect URL.
     * Stripe round-trip, covered by the staging E2E checklist, not unit tests.
     */
    public function execute(Workspace $workspace, string $interval): string
    {
        $builder = $workspace
            ->newSubscription('default', $this->priceId($interval))
            ->allowPromotionCodes();

        $trialEndsAt = $workspace->onGenericTrial() ? $workspace->trial_ends_at : null;

        $builder = $trialEndsAt instanceof CarbonInterface
            ? $builder->trialUntil($trialEndsAt)
            : $builder->skipTrial();

        return (string) $builder
            ->checkout($this->sessionOptions($workspace, ! $this->entitlements->allowsAccess($workspace)))
            ->asStripeCheckoutSession()
            ->url;
    }

    private function priceId(string $interval): string
    {
        // $interval arrives from the browser, so pin it to the known intervals and
        // an arbitrary string never reaches a config lookup.
        throw_unless(in_array($interval, self::INTERVALS, true), InvalidArgumentException::class, "Unsupported billing interval [{$interval}].");

        $priceId = config("services.stripe.prices.pro_{$interval}");

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for interval [{$interval}].");

        return $priceId;
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Workspace $workspace, bool $reopensWorkspace): array
    {
        $billingUrl = Billing::getUrl(panel: 'app', tenant: $workspace);
        $outcome = $reopensWorkspace ? Billing::CHECKOUT_REOPENED : Billing::CHECKOUT_SUCCESS;

        $options = [
            'success_url' => "{$billingUrl}?checkout={$outcome}",
            'cancel_url' => $billingUrl,
            'client_reference_id' => (string) $workspace->getKey(),
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
