<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Queries;

use App\Enums\BillingStatus;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Billing\Commands\ScheduleInactiveTrialDeletionsCommand;

final readonly class InactiveTrialWorkspacesQuery
{
    /**
     * @return LazyCollection<int, Workspace>
     */
    public function candidates(): LazyCollection
    {
        return $this->eligible()
            ->notScheduledForDeletion()
            ->whereHas('owner')
            ->with(['owner', 'subscriptions'])
            ->lazyById(100)
            ->reject(static fn (Workspace $workspace): bool => self::owesManagedInvoice($workspace));
    }

    public function isEligible(Workspace $workspace): bool
    {
        $eligible = $this->eligible()->with('subscriptions')->find($workspace->getKey());

        return $eligible instanceof Workspace && ! self::owesManagedInvoice($eligible);
    }

    public function isExempt(Workspace $workspace): bool
    {
        return $workspace->hosted_free_grandfathered_at !== null
            || $this->creditPurchases()->where('workspace_id', $workspace->getKey())->exists()
            || self::owesManagedInvoice($workspace);
    }

    public function stillDue(Workspace $workspace): bool
    {
        $scheduledFor = $workspace->scheduled_deletion_at ?? now();

        return $this->isEligible($workspace)
            && $this->lastActivity($workspace)->addDays(ScheduleInactiveTrialDeletionsCommand::KEEP_DAYS)->lessThanOrEqualTo($scheduledFor->addDay());
    }

    /**
     * @return Builder<Workspace>
     */
    private function eligible(): Builder
    {
        return BillingStatus::TrialEnded->applyToQuery(Workspace::query())
            ->whereNotNull('paused_at')
            ->whereNull('trial_ends_at')
            ->whereNotIn('id', $this->creditPurchases()->select('workspace_id'));
    }

    /**
     * @return Builder<AiCreditTransaction>
     */
    private function creditPurchases(): Builder
    {
        return AiCreditTransaction::query()->where('type', AiCreditType::Purchase);
    }

    private static function owesManagedInvoice(Workspace $workspace): bool
    {
        return $workspace->pendingManagedSubscription() instanceof Subscription;
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
