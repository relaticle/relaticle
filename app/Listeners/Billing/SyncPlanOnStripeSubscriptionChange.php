<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Actions\Billing\SyncWorkspacePlanFromSubscription;
use App\Models\Workspace;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

final readonly class SyncPlanOnStripeSubscriptionChange
{
    public function __construct(private SyncWorkspacePlanFromSubscription $syncWorkspacePlan) {}

    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? '';

        if (! str_starts_with((string) $type, 'customer.subscription.')) {
            return;
        }

        $stripeId = $event->payload['data']['object']['id'] ?? null;

        if (! is_string($stripeId)) {
            return;
        }

        $subscription = Subscription::query()->firstWhere('stripe_id', $stripeId);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $workspace = $subscription->owner()->first();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $this->syncWorkspacePlan->execute($workspace, $subscription);
    }
}
