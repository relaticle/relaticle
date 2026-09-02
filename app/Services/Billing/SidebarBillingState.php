<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Team;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;

/**
 * The sidebar's one-line billing prompt. Derives from the same state the
 * Billing page shows, so the two cannot tell a workspace different stories.
 *
 * Returns null for every workspace with nothing to ask for: paying subscribers,
 * Enterprise, grandfathered free, and any install with billing switched off.
 */
final readonly class SidebarBillingState
{
    public function __construct(private HostedWorkspaceAccess $access) {}

    /**
     * `urgent` separates a failure from an offer: past due is the only state
     * here reporting something already broken, and the row renders it in danger
     * colours rather than the same neutral pill as an upgrade prompt.
     *
     * @return array{label: string, action: string, urgent: bool}|null
     */
    public function for(Team $team): ?array
    {
        if (! Feature::active(Billing::class)) {
            return null;
        }

        // Ranked above the valid() check for the same reason BillingStatus ranks
        // PastDue above Subscribed: keepPastDueSubscriptionsActive() leaves
        // valid() true throughout dunning, so asking it first returns null and
        // leaves the one state that ends in losing the workspace unmentioned.
        if ($team->subscription()?->pastDue() === true) {
            return [
                'label' => __('billing.sidebar.past_due'),
                'action' => __('billing.sidebar.fix'),
                'urgent' => true,
            ];
        }

        if ($team->subscription()?->valid() === true || $team->plan === Plan::Enterprise) {
            return null;
        }

        if ($team->onGenericTrial()) {
            return [
                'label' => trans_choice('billing.sidebar.trial_days_left', $this->daysLeft($team), [
                    'days' => $this->daysLeft($team),
                ]),
                'action' => __('billing.sidebar.keep_pro'),
                'urgent' => false,
            ];
        }

        // A grandfathered free workspace still has access and nothing to buy,
        // so it gets no prompt. Everything else that cannot reach the app is
        // paused and needs the only row here that must never 403.
        if ($this->access->allows($team)) {
            return null;
        }

        return [
            'label' => __('billing.sidebar.paused'),
            'action' => __('billing.sidebar.subscribe'),
            'urgent' => false,
        ];
    }

    /**
     * Same calculation the Billing page renders, so a workspace never reads
     * "3 days left" in one place and "2 days left" in the other. Carbon 3's
     * diffInDays() already returns a float, which is what the page's older
     * floatDiffInDays() alias resolves to.
     */
    private function daysLeft(Team $team): int
    {
        if ($team->trial_ends_at === null) {
            return 0;
        }

        return max(0, (int) ceil(Date::now()->diffInDays($team->trial_ends_at)));
    }
}
