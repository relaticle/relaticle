<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingStatus;
use App\Features\Billing;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;

/**
 * The sidebar's one-line billing prompt. Derives from `BillingStatus`, the same
 * owner the Billing page reads, so the two cannot tell a workspace different stories.
 *
 * Returns null for every workspace with nothing to ask for: paying subscribers,
 * Enterprise, grandfathered free, and any install with billing switched off.
 */
final readonly class SidebarBillingState
{
    /**
     * @return array{label: string, action: string, urgent: bool}|null
     */
    public function for(Workspace $workspace): ?array
    {
        if (! Feature::active(Billing::class)) {
            return null;
        }

        $status = $workspace->billingStatus();

        if ($status === BillingStatus::PastDue) {
            return [
                'label' => __('billing.sidebar.past_due'),
                'action' => __('billing.sidebar.fix'),
                'urgent' => true,
            ];
        }

        if ($status === BillingStatus::Trialing) {
            return [
                'label' => trans_choice('billing.sidebar.trial_days_left', $this->daysLeft($workspace), [
                    'days' => $this->daysLeft($workspace),
                ]),
                'action' => __('billing.sidebar.keep_pro'),
                'urgent' => false,
            ];
        }

        if ($status->grantsAccess()) {
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
    private function daysLeft(Workspace $workspace): int
    {
        if ($workspace->trial_ends_at === null) {
            return 0;
        }

        return max(0, (int) ceil(Date::now()->diffInDays($workspace->trial_ends_at)));
    }
}
