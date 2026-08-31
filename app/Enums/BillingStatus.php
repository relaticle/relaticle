<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Team;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;

/**
 * Why a workspace has the plan it has.
 *
 * `teams.plan` records capability, not provenance: a trial writes `Plan::Pro`
 * so that the workspace gets Pro credits and rate limits, which makes a
 * trialling workspace indistinguishable from a paying one wherever the plan
 * column is rendered on its own. This answers the question that column cannot.
 *
 * Read-only and derived. Nothing persists it, and it deliberately says nothing
 * about whether a workspace can currently reach the app. That is
 * `HostedWorkspaceAccess`, which layers the billing feature flag on top.
 */
enum BillingStatus: string implements HasColor, HasDescription, HasLabel
{
    case PastDue = 'past_due';

    case Subscribed = 'subscribed';

    case Enterprise = 'enterprise';

    case Trialing = 'trialing';

    case Grandfathered = 'grandfathered';

    case Granted = 'granted';

    case Free = 'free';

    /**
     * Ordered by precedence, most specific first. Past due comes before
     * subscribed because this app calls `Cashier::keepPastDueSubscriptionsActive()`,
     * which leaves `valid()` true for a subscription that has stopped paying.
     */
    public static function fromTeam(Team $team): self
    {
        $subscription = $team->subscription();

        if ($subscription?->pastDue() === true) {
            return self::PastDue;
        }

        if ($subscription?->valid() === true) {
            return self::Subscribed;
        }

        if ($team->plan === Plan::Enterprise) {
            return self::Enterprise;
        }

        if ($team->onGenericTrial()) {
            return self::Trialing;
        }

        if ($team->hosted_free_grandfathered_at !== null) {
            return self::Grandfathered;
        }

        if ($team->plan !== Plan::Free) {
            return self::Granted;
        }

        return self::Free;
    }

    /**
     * The query counterpart of fromTeam(), so a table can be filtered by the
     * badge it renders.
     *
     * fromTeam() returns on the first predicate that holds, which a filter has
     * to reproduce: matching Subscribed on its own would also return every
     * past-due workspace. Case declaration order is the precedence order, so
     * every status that outranks this one is excluded before its own predicate
     * applies.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function applyToQuery(Builder $query): Builder
    {
        foreach (self::cases() as $case) {
            if ($case === $this) {
                break;
            }

            $query->whereNot(fn (Builder $outranking): Builder => $case->constrain($outranking));
        }

        return $this->constrain($query);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PastDue => 'Past due',
            self::Subscribed => 'Pro',
            self::Enterprise => 'Enterprise',
            self::Trialing => 'Trial',
            self::Grandfathered => 'Free (legacy)',
            self::Granted => 'Granted',
            self::Free => 'Free',
        };
    }

    /**
     * The one-line reason behind the badge, for the tooltip the sysadmin
     * tables hang off it. A label alone cannot separate a plan someone paid
     * for from one an admin typed in.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::PastDue => 'Paid subscription whose latest charge failed. Access stays open until Stripe cancels it.',
            self::Subscribed => 'Paying for Pro through a live Stripe subscription.',
            self::Enterprise => 'Put on the Enterprise plan by hand. No self-serve price exists for it.',
            self::Trialing => 'Running an unexpired Pro trial. Nothing has been charged yet.',
            self::Grandfathered => 'Existed before billing shipped, so hosted access stays free for good.',
            self::Granted => 'Given a paid plan by hand, with no subscription or trial behind it.',
            self::Free => 'No subscription, trial, or grandfathering. Hosted access is paused while billing is on.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PastDue => 'danger',
            self::Subscribed => 'success',
            self::Enterprise => 'primary',
            self::Trialing => 'info',
            self::Grandfathered, self::Free => 'gray',
            self::Granted => 'warning',
        };
    }

    /**
     * This status's own predicate, blind to the statuses that outrank it.
     * Only applyToQuery() may call it.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    private function constrain(Builder $query): Builder
    {
        return match ($this) {
            self::PastDue => $query->whereHas('latestDefaultSubscription', self::pastDue(...)),
            self::Subscribed => $query->whereHas('latestDefaultSubscription', self::valid(...)),
            self::Enterprise => $query->where('plan', Plan::Enterprise),
            self::Trialing => $query->onGenericTrial(),
            self::Grandfathered => $query->whereNotNull('hosted_free_grandfathered_at'),
            self::Granted => $query->whereNot('plan', Plan::Free),
            self::Free => $query->where('plan', Plan::Free),
        };
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function pastDue(Builder $query): Builder
    {
        return $query->pastDue();
    }

    /**
     * Cashier's `Subscription::valid()` as a query. Cashier ships no
     * `scopeValid`, so the three scopes behind it are OR'd here rather than a
     * stripe_status list being hardcoded and drifting from the predicate the
     * badge uses.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function valid(Builder $query): Builder
    {
        return $query->where(fn (Builder $subscription): Builder => $subscription
            ->where(fn (Builder $active): Builder => $active->active())
            ->orWhere(fn (Builder $onTrial): Builder => $onTrial->onTrial())
            ->orWhere(fn (Builder $onGracePeriod): Builder => $onGracePeriod->onGracePeriod()));
    }
}
