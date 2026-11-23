<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Throwable;

final readonly class KeepWorkspaceSubscription
{
    public function execute(User $user, Workspace $workspace): bool
    {
        throw_unless($user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage), AuthorizationException::class);

        $subscription = $workspace->subscription();

        if ($workspace->plan->isManaged() || ! $subscription instanceof Subscription || ! $subscription->onGracePeriod()) {
            return false;
        }

        $subscription->resume();

        try {
            Cashier::stripe()->subscriptions->update($subscription->stripe_id, ['metadata' => [
                'kept_by_user_id' => (string) $user->getKey(),
                'kept_at' => now()->toIso8601String(),
            ]]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}
