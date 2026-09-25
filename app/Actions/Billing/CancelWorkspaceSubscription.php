<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Throwable;

final readonly class CancelWorkspaceSubscription
{
    public function execute(Workspace $workspace, bool $immediately = false): void
    {
        // Cashier's subscription() reads the relation off the model. Deletion
        // paths arrive with a workspace that was never loaded with its subscriptions
        // (DeleteUser walks owned workspaces, the purge command chunks them), which
        // is both an N+1 and a strict-lazy-loading violation outside production.
        $workspace->loadMissing('subscriptions');

        $subscription = $workspace->subscription();

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            return;
        }

        try {
            $immediately ? $subscription->cancelNow() : $subscription->cancel();
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
