<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing;

use App\Contracts\Billing;
use App\Data\SubscriptionEnding;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\Actions\CancelWorkspaceSubscription;
use Relaticle\Cloud\Billing\Actions\StartProTrial;
use Relaticle\Cloud\Billing\Data\Renewal;
use Relaticle\Cloud\Billing\Filament\Billing as BillingPage;
use Relaticle\Cloud\Billing\Jobs\RefreshRenewal;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

final readonly class StripeBilling implements Billing
{
    public function __construct(
        private StartProTrial $startProTrial,
        private CancelWorkspaceSubscription $cancelSubscription,
        private CreditPackCatalog $creditPacks,
        private InactiveTrialWorkspacesQuery $inactive,
    ) {}

    public function url(Workspace $workspace): ?string
    {
        if (! Feature::active(BillingFeature::class)) {
            return null;
        }

        return BillingPage::getUrl(panel: 'app', tenant: $workspace);
    }

    public function sellsCredits(Workspace $workspace): bool
    {
        return $this->creditPacks->hasPurchasable();
    }

    public function startTrial(User $owner, Workspace $workspace): void
    {
        $this->startProTrial->execute($owner, $workspace);
    }

    public function cancelForDeletion(Workspace $workspace, bool $immediately): void
    {
        $this->cancelSubscription->execute($workspace, $immediately);
    }

    public function blocksDeletion(Workspace $workspace): bool
    {
        return $workspace->hasOpenManagedSubscription();
    }

    public function deletionNotice(Workspace $workspace): ?string
    {
        if (! $workspace->subscribed()) {
            return null;
        }

        return __('cloud::billing.deletion_notice');
    }

    public function inactivityDeletionStands(Workspace $workspace): bool
    {
        return $workspace->inactivity_reminded_at !== null && $this->inactive->stillDue($workspace);
    }

    public function subscriptionEnding(Workspace $workspace): ?SubscriptionEnding
    {
        $subscription = $workspace->subscription();
        $plan = Plan::fromStripePrice($subscription?->stripe_price);

        if (! Feature::active(BillingFeature::class) || $workspace->plan->isManaged() || ! $subscription instanceof Subscription || ! $subscription->onGracePeriod() || ! $plan instanceof Plan || $subscription->ends_at === null) {
            return null;
        }

        $renewal = Renewal::remembered($subscription->stripe_id)
            ?? rescue(static function () use ($subscription): ?Renewal {
                dispatch_sync(new RefreshRenewal($subscription->stripe_id));

                return Renewal::remembered($subscription->stripe_id);
            });

        if (! $renewal instanceof Renewal) {
            return null;
        }

        $yearly = Plan::intervalFromStripePrice($subscription->stripe_price) === 'yearly';
        $replace = [
            'plan' => $plan->marketingLabel(),
            'date' => $subscription->ends_at->isoFormat('ll'),
            'price' => $renewal->amount(),
        ];

        return new SubscriptionEnding(
            notice: __('cloud::billing.keep.ending_notice', $replace),
            keepLabel: __('cloud::billing.keep.button', $replace),
            heading: __('cloud::billing.keep.heading', $replace),
            keepUrl: BillingPage::getUrl(['action' => BillingPage::KEEP_SUBSCRIPTION_ACTION], panel: 'app', tenant: $workspace),
            terms: __($yearly ? 'cloud::billing.keep.terms_yearly' : 'cloud::billing.keep.terms_monthly', $replace),
            keptMessage: __('cloud::billing.keep.kept', $replace),
        );
    }
}
