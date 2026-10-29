<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\StripeSubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Throwable;

final readonly class CancelWorkspaceSubscription
{
    public function __construct(private VoidOpenInvoices $voidOpenInvoices) {}

    public function execute(Workspace $workspace, bool $immediately = false): void
    {
        // Cashier's subscription() reads the relation off the model. Deletion
        // paths arrive with a workspace that was never loaded with its subscriptions
        // (DeleteUser walks owned workspaces, the purge command chunks them), which
        // is both an N+1 and a strict-lazy-loading violation outside production.
        $workspace->loadMissing('subscriptions');
        $workspace->subscriptions->each->setRelation('owner', $workspace);

        if ($immediately) {
            $this->cancelManagedSubscriptions($workspace);
        }

        $subscription = $workspace->subscription();

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            return;
        }

        $this->attempt($workspace, $subscription, $immediately, fn (): Subscription => $immediately ? $subscription->cancelNow() : $subscription->cancel());
    }

    private function cancelManagedSubscriptions(Workspace $workspace): void
    {
        $managed = $workspace->subscriptions->filter(fn (mixed $subscription): bool => $subscription instanceof Subscription
            && in_array($subscription->type, SubscriptionType::managed(), true)
            && $subscription->stripe_status !== StripeSubscriptionStatus::Canceled->value
            && ! $subscription->ended());

        foreach ($managed as $subscription) {
            $this->attempt($workspace, $subscription, true, function () use ($subscription): void {
                $this->voidOpenInvoices->execute($subscription);
                $subscription->noProrate()->cancelNow();
            });
        }
    }

    private function attempt(Workspace $workspace, Subscription $subscription, bool $immediately, callable $cancel): void
    {
        try {
            $cancel();
        } catch (Throwable $exception) {
            Log::error('Failed to cancel Stripe subscription during workspace deletion', [
                'workspace_id' => $workspace->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'immediately' => $immediately,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
