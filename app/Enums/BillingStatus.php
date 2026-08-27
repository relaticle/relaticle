<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Team;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a workspace has the plan it has.
 *
 * `teams.plan` records capability, not provenance: a trial writes `Plan::Pro`
 * so that the workspace gets Pro credits and rate limits, which makes a
 * trialling workspace indistinguishable from a paying one wherever the plan
 * column is rendered on its own. This answers the question that column cannot.
 *
 * Read-only and derived. Nothing persists it, and it deliberately says nothing
 * about whether a workspace can currently reach the app — that is
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
}
