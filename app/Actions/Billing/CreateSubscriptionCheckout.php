<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Filament\Pages\Billing;
use App\Models\Workspace;
use App\Services\Billing\HostedWorkspaceAccess;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CreateSubscriptionCheckout
{
    /** @var list<string> */
    public const array INTERVALS = ['monthly', 'yearly'];

    public function __construct(private HostedWorkspaceAccess $hostedAccess) {}

    /**
     * Create the hosted Stripe Checkout session for a self-serve plan and return
     * its redirect URL. Stripe round-trip, covered by the staging E2E checklist,
     * not unit tests.
     */
    public function execute(Workspace $workspace, Plan $plan, string $interval): string
    {
        $builder = $workspace
            ->newSubscription('default', $this->priceId($plan, $interval))
            ->allowPromotionCodes();

        $trialEndsAt = $workspace->onGenericTrial() ? $workspace->trial_ends_at : null;

        $builder = $trialEndsAt instanceof CarbonInterface
            ? $builder->trialUntil($trialEndsAt)
            : $builder->skipTrial();

        return (string) $builder
            ->checkout($this->sessionOptions($workspace, $this->hostedAccess->isPaused($workspace)))
            ->asStripeCheckoutSession()
            ->url;
    }

    private function priceId(Plan $plan, string $interval): string
    {
        // Both arrive from the browser, so pin them to the sellable plans and the
        // known intervals before anything reaches a config lookup.
        throw_unless($plan->isSelfServe(), InvalidArgumentException::class, "Plan [{$plan->value}] is not sold through checkout.");
        throw_unless(in_array($interval, self::INTERVALS, true), InvalidArgumentException::class, "Unsupported billing interval [{$interval}].");

        $priceId = config("services.stripe.prices.{$plan->value}_{$interval}");

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for [{$plan->value}_{$interval}].");

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
