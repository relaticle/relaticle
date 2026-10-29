<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Enums\StripeSubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Jobs\RefreshManagedRenewal;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;
use Stripe\Subscription as StripeSubscription;
use UnexpectedValueException;

final readonly class ActivateManagedPlan
{
    public function __construct(
        private CreditService $credits,
        private CancelWithUnusedTimeRefund $cancelWithRefund,
    ) {}

    public function execute(Workspace $workspace, string $stripeSubscriptionId, string $invoiceId): void
    {
        $subscription = $this->localSubscription($workspace, $stripeSubscriptionId);
        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId);

        if ($subscription->type === SubscriptionType::Managed->value) {
            if ($subscription->valid() && in_array($stripeSubscription->status, StripeSubscriptionStatus::live(), true)) {
                $this->finishSwitch($workspace, $stripeSubscription, $invoiceId);
            }

            return;
        }

        $plan = $this->planToGrant($workspace, $stripeSubscription, $invoiceId);

        if (! $plan instanceof Plan || ! $this->grant($workspace, $stripeSubscriptionId, $plan)) {
            return;
        }

        $this->finishSwitch($workspace, $stripeSubscription, $invoiceId);

        Log::info('Managed plan switched on', [
            'workspace_id' => $workspace->getKey(),
            'plan' => $plan->value,
            'subscription_id' => $stripeSubscriptionId,
            'invoice_id' => $invoiceId,
        ]);
    }

    private function planToGrant(Workspace $workspace, StripeSubscription $stripeSubscription, string $invoiceId): ?Plan
    {
        $product = $stripeSubscription->items->data[0]->price->product ?? null;
        $plan = Plan::fromStripeProduct(is_string($product) ? $product : null);

        if (! $plan instanceof Plan) {
            $this->refuse('product is not a managed plan', $workspace, $stripeSubscription->id, $invoiceId);

            return null;
        }

        if (($stripeSubscription->metadata->workspace_id ?? null) !== (string) $workspace->getKey()) {
            $this->refuse('workspace mismatch', $workspace, $stripeSubscription->id, $invoiceId);

            return null;
        }

        if (! in_array($stripeSubscription->status, StripeSubscriptionStatus::live(), true)) {
            $this->refuse("subscription is {$stripeSubscription->status}", $workspace, $stripeSubscription->id, $invoiceId);

            return null;
        }

        return $plan;
    }

    private function finishSwitch(Workspace $workspace, StripeSubscription $stripeSubscription, string $invoiceId): void
    {
        if (($stripeSubscription->metadata->type ?? null) !== SubscriptionType::Managed->value) {
            Cashier::stripe()->subscriptions->update($stripeSubscription->id, [
                'metadata' => ['type' => SubscriptionType::Managed->value],
            ]);
        }

        $this->cancelReplaced($workspace, $stripeSubscription->id, $invoiceId);

        dispatch(new RefreshManagedRenewal($stripeSubscription->id));
    }

    private function cancelReplaced(Workspace $workspace, string $activatedId, string $invoiceId): void
    {
        $paidAt = $this->paidAt($invoiceId);

        $replaced = $this->subscriptionsOf($workspace)
            ->whereIn('type', [SubscriptionType::Default->value, SubscriptionType::Managed->value])
            ->where('stripe_id', '!=', $activatedId)
            ->where('created_at', '<=', $paidAt)
            ->get()
            ->each->setRelation('owner', $workspace)
            ->filter(fn (Subscription $subscription): bool => $subscription->valid() || $subscription->stripe_status === StripeSubscriptionStatus::Unpaid->value);

        foreach ($replaced as $subscription) {
            $this->cancelWithRefund->execute($subscription, "managed-switch-{$invoiceId}-{$subscription->stripe_id}", $paidAt);
        }
    }

    private function paidAt(string $invoiceId): CarbonImmutable
    {
        $paidAt = Cashier::stripe()->invoices->retrieve($invoiceId)->status_transitions->paid_at ?? null;

        throw_unless(is_int($paidAt), UnexpectedValueException::class, "Invoice {$invoiceId} has no paid time.");

        return Date::createFromTimestamp($paidAt);
    }

    private function grant(Workspace $workspace, string $stripeSubscriptionId, Plan $plan): bool
    {
        return DB::transaction(function () use ($workspace, $stripeSubscriptionId, $plan): bool {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            $subscription = $this->localSubscription($workspace, $stripeSubscriptionId);

            if ($subscription->type === SubscriptionType::Managed->value) {
                return false;
            }

            $subscription->forceFill(['type' => SubscriptionType::Managed->value])->save();

            $locked->forceFill(['plan' => $plan, 'trial_ends_at' => null])->save();

            $this->credits->resetPeriod($locked);

            return true;
        });
    }

    private function localSubscription(Workspace $workspace, string $stripeSubscriptionId): Subscription
    {
        return $this->subscriptionsOf($workspace)->where('stripe_id', $stripeSubscriptionId)->firstOrFail();
    }

    /** @return Builder<Subscription> */
    private function subscriptionsOf(Workspace $workspace): Builder
    {
        return Subscription::query()->where($workspace->getForeignKey(), $workspace->getKey());
    }

    // The invoice is paid, so a refusal leaves money with nothing granted and must reach on-call.
    private function refuse(string $reason, Workspace $workspace, string $stripeSubscriptionId, string $invoiceId): void
    {
        Log::warning("Managed plan activation ignored: {$reason}", [
            'workspace_id' => $workspace->getKey(),
            'subscription_id' => $stripeSubscriptionId,
            'invoice_id' => $invoiceId,
        ]);

        report(new UnexpectedValueException(
            "Managed invoice {$invoiceId} for subscription {$stripeSubscriptionId} of workspace {$workspace->getKey()} was paid and nothing was granted: {$reason}.",
        ));
    }
}
