<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class CancelTeamSubscription
{
    public function handle(Team $team, bool $immediately = false): void
    {
        $subscription = $team->subscription();

        if ($subscription === null || $subscription->ended()) {
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
