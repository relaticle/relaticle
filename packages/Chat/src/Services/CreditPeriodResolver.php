<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Billing\StartProTrial;
use App\Models\Team;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class CreditPeriodResolver
{
    /**
     * Safety cap on the elapsed-months convergence loop in anniversaryCycle().
     * Empirically the diffInMonths() starting guess is never more than 1
     * clamped month off; this bound only exists so a future Carbon behavior
     * change fails loudly instead of spinning.
     */
    private const int MAX_CYCLE_ADJUSTMENTS = 6;

    /**
     * Credit-period bounds for a team, per the billing spec's policy table:
     * subscription anniversary cycle > trial span > calendar month.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function boundsFor(Team $team): array
    {
        // Load explicitly rather than relying on the caller: strict lazy loading
        // arms only on multi-row hydrations, so a bulk caller that forgets to
        // eager-load would otherwise fail in tests and silently N+1 in production.
        // Callers that do eager-load pay nothing here.
        $team->loadMissing('subscriptions');

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
     * diffInMonths() only seeds a starting guess for $elapsed: it uses
     * overflow-style month arithmetic internally and disagrees with the
     * clamped addMonthsNoOverflow() used for $start/$end, by up to a month
     * near month-end anchors (e.g. Jan 31 anchor, now = Feb 28 afternoon).
     * The loop below walks $elapsed toward whichever direction the mismatch
     * points until start <= now < end holds exactly, rather than trusting
     * the guess or only correcting an over-estimate.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function anniversaryCycle(Carbon $anchor): array
    {
        $now = now();
        $elapsed = max(0, (int) $anchor->diffInMonths($now));

        for ($i = 0; $i <= self::MAX_CYCLE_ADJUSTMENTS; $i++) {
            $start = $anchor->copy()->addMonthsNoOverflow($elapsed);
            $end = $anchor->copy()->addMonthsNoOverflow($elapsed + 1);

            if ($start->lessThanOrEqualTo($now) && $now->lessThan($end)) {
                return ['start' => $start, 'end' => $end];
            }

            $elapsed = max(0, $elapsed + ($start->greaterThan($now) ? -1 : 1));
        }

        // Exhausting the cap without converging must fail loudly: silently
        // returning a window may already be in the past, which would make
        // chat:reset-credits re-grant a full allowance every night.
        throw new RuntimeException(sprintf(
            'CreditPeriodResolver failed to converge on an anniversary cycle for anchor %s (now %s) after %d adjustments.',
            $anchor->toIso8601String(),
            $now->toIso8601String(),
            self::MAX_CYCLE_ADJUSTMENTS,
        ));
    }
}
