<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Throwable;

final readonly class CancelTeamSubscription
{
    public function execute(Team $team, bool $immediately = false): void
    {
        // Cashier's subscription() reads the relation off the model. Deletion
        // paths arrive with a team that was never loaded with its subscriptions
        // (DeleteUser walks owned teams, the purge command chunks them), which
        // is both an N+1 and a strict-lazy-loading violation outside production.
        $team->loadMissing('subscriptions');

        $subscription = $team->subscription();

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            return;
        }

        try {
            $immediately ? $subscription->cancelNow() : $subscription->cancel();
        } catch (Throwable $exception) {
            Log::error('Failed to cancel Stripe subscription during team deletion', [
                'team_id' => $team->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'immediately' => $immediately,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
