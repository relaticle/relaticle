<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Billing\StartProTrial;
use App\Models\Team;
use Illuminate\Support\Carbon;

final readonly class CreditPeriodResolver
{
    /**
     * Credit-period bounds for a team, per the billing spec's policy table:
     * subscription anniversary cycle > trial span > calendar month.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function boundsFor(Team $team): array
    {
        $subscription = $team->subscription();

        if ($subscription?->valid() === true) {
            /** @var Carbon $anchor */
            $anchor = $subscription->created_at;

            return $this->anniversaryCycle($anchor);
        }

        if ($team->onGenericTrial() && $team->trial_ends_at !== null) {
            return [
                'start' => $team->trial_ends_at->copy()->subDays(StartProTrial::TRIAL_DAYS),
                'end' => $team->trial_ends_at->copy(),
            ];
        }

        return ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()];
    }

    /**
     * The monthly cycle containing now(), computed from the anchor each time
     * (never chained) so month-end anchors clamp without drifting:
     * Jan 31 -> Feb 28 -> Mar 31.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function anniversaryCycle(Carbon $anchor): array
    {
        $elapsed = max(0, (int) $anchor->diffInMonths(now()));

        $start = $anchor->copy()->addMonthsNoOverflow($elapsed);

        if ($start->greaterThan(now())) {
            $elapsed = max(0, $elapsed - 1);
            $start = $anchor->copy()->addMonthsNoOverflow($elapsed);
        }

        return ['start' => $start, 'end' => $anchor->copy()->addMonthsNoOverflow($elapsed + 1)];
    }
}
