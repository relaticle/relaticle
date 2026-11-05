<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Queries;

use App\Enums\BillingStatus;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class InactiveTrialWorkspacesQuery
{
    /**
     * @return LazyCollection<int, Workspace>
     */
    public function candidates(): LazyCollection
    {
        return BillingStatus::TrialEnded->applyToQuery(Workspace::query())
            ->whereNotNull('paused_at')
            ->whereNull('trial_ends_at')
            ->notScheduledForDeletion()
            ->whereHas('owner')
            ->whereNotIn('id', AiCreditTransaction::query()->where('type', AiCreditType::Purchase)->select('workspace_id'))
            ->with(['owner', 'subscriptions'])
            ->lazyById(100)
            ->reject(static fn (Workspace $workspace): bool => $workspace->pendingManagedSubscription() !== null);
    }

    public function lastActivity(Workspace $workspace): CarbonImmutable
    {
        $pausedAt = $workspace->paused_at ?? $workspace->created_at;

        $lastLogin = User::query()
            ->whereKey($workspace->user_id)
            ->orWhereIn('id', Membership::query()->where('workspace_id', $workspace->getKey())->select('user_id'))
            ->max('last_login_at');

        if (! is_string($lastLogin)) {
            return $pausedAt;
        }

        return $pausedAt->max(Date::parse($lastLogin));
    }
}
