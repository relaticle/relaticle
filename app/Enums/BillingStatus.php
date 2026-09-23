<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Workspace;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription;

/**
 * Why a workspace has the plan it has.
 *
 * `workspaces.plan` records capability, not provenance: a trial writes `Plan::Pro`
 * so that the workspace gets Pro credits and rate limits, which makes a
 * trialling workspace indistinguishable from a paying one wherever the plan
 * column is rendered on its own. This answers the question that column cannot.
 *
 * Read-only and derived. Nothing persists it. It is the one owner of billing
 * state: the access gate, the sidebar, the paused screen, and the ended email
 * all read it. `HostedWorkspaceAccess` only layers the billing feature flag on
 * top of `grantsAccess()`.
 */
enum BillingStatus: string implements HasColor, HasDescription, HasLabel
{
    case PastDue = 'past_due';

    case Subscribed = 'subscribed';

    case Enterprise = 'enterprise';

    case Trialing = 'trialing';

    case Grandfathered = 'grandfathered';

    case SubscriptionEnded = 'subscription_ended';

    case TrialEnded = 'trial_ended';

    case Granted = 'granted';

    case Free = 'free';

    /**
     * Ordered by precedence, most specific first. Past due comes before
     * subscribed because this app calls `Cashier::keepPastDueSubscriptionsActive()`,
     * which leaves `valid()` true for a subscription that has stopped paying.
     */
    public static function fromWorkspace(Workspace $workspace): self
    {
        $subscription = $workspace->subscription();

        if ($subscription?->pastDue() === true) {
            return self::PastDue;
        }

        if ($subscription?->valid() === true) {
            return self::Subscribed;
        }

        if ($workspace->plan === Plan::Enterprise) {
            return self::Enterprise;
        }

        if ($workspace->onGenericTrial()) {
            return self::Trialing;
        }

        if ($workspace->hosted_free_grandfathered_at !== null) {
            return self::Grandfathered;
        }

        if ($workspace->plan === Plan::Free && self::hasChargedSubscription($workspace)) {
            return self::SubscriptionEnded;
        }

        if ($workspace->trial_ends_at !== null) {
            return self::TrialEnded;
        }

        // The nightly downgrade clears trial_ends_at, so only the trial marker is left.
        if ($workspace->plan === Plan::Free && $workspace->pro_trial_used_at !== null) {
            return self::TrialEnded;
        }

        if ($workspace->plan !== Plan::Free) {
            return self::Granted;
        }

        return self::Free;
    }

    /**
     * The query counterpart of fromWorkspace(), so a table can be filtered by the
     * badge it renders.
     *
     * fromWorkspace() returns on the first predicate that holds, which a filter has
     * to reproduce: matching Subscribed on its own would also return every
     * past-due workspace. Case declaration order is the precedence order, so
     * every status that outranks this one is excluded before its own predicate
     * applies.
     *
     * @param  Builder<Workspace>  $query
     * @return Builder<Workspace>
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

    public function grantsAccess(): bool
    {
        return match ($this) {
            self::PastDue, self::Subscribed, self::Enterprise, self::Trialing, self::Grandfathered, self::Granted => true,
            self::SubscriptionEnded, self::TrialEnded, self::Free => false,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PastDue => 'Past due',
            self::Subscribed => 'Pro',
            self::Enterprise => 'Enterprise',
            self::Trialing => 'Trial',
            self::Grandfathered => 'Free (legacy)',
            self::SubscriptionEnded => 'Subscription ended',
            self::TrialEnded => 'Trial ended',
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
            self::SubscriptionEnded => 'Paid subscription ended with nothing behind it. Hosted access is paused until the owner subscribes again.',
            self::TrialEnded => 'Pro trial expired with no subscription. Hosted access is paused until the owner subscribes.',
            self::Granted => 'Given a paid plan by hand, with no subscription or trial behind it.',
            self::Free => 'No subscription, trial, or grandfathering. Hosted access is paused while billing is on.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PastDue, self::SubscriptionEnded, self::TrialEnded => 'danger',
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
     * @param  Builder<Workspace>  $query
     * @return Builder<Workspace>
     */
    private function constrain(Builder $query): Builder
    {
        return match ($this) {
            self::PastDue => $query->whereHas('latestDefaultSubscription', self::pastDue(...)),
            self::Subscribed => $query->whereHas('latestDefaultSubscription', self::valid(...)),
            self::Enterprise => $query->where('plan', Plan::Enterprise),
            self::Trialing => $query->onGenericTrial(),
            self::Grandfathered => $query->whereNotNull('hosted_free_grandfathered_at'),
            // Any row, not the latest: a later abandoned checkout must not hide one that ended.
            self::SubscriptionEnded => $query->where('plan', Plan::Free)->whereHas('subscriptions', self::charged(...)),
            self::TrialEnded => $query->where(fn (Builder $ended): Builder => $ended
                ->whereNotNull('trial_ends_at')
                ->orWhere(fn (Builder $downgraded): Builder => $downgraded->where('plan', Plan::Free)->whereNotNull('pro_trial_used_at'))),
            self::Granted => $query->whereNot('plan', Plan::Free),
            self::Free => $query->where('plan', Plan::Free),
        };
    }

    private static function hasChargedSubscription(Workspace $workspace): bool
    {
        return $workspace->subscriptions->contains(
            fn (Model $subscription): bool => $subscription instanceof Subscription
                && ! in_array($subscription->stripe_status, StripeSubscriptionStatus::neverGranted(), true),
        );
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function charged(Builder $query): Builder
    {
        return $query->whereNotIn('stripe_status', StripeSubscriptionStatus::neverGranted());
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
