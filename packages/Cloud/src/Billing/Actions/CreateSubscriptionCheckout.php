<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Contracts\Entitlements;
use App\Enums\Plan;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Relaticle\Cloud\Billing\Filament\Billing;

final readonly class CreateSubscriptionCheckout
{
    /** @var list<string> */
    public const array INTERVALS = ['monthly', 'yearly'];

    public function __construct(private Entitlements $entitlements) {}

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
            ->checkout($this->sessionOptions($workspace, ! $this->entitlements->allowsAccess($workspace)))
            ->asStripeCheckoutSession()
            ->url;
    }

    private function priceId(Plan $plan, string $interval): string
    {
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
