<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * The `subscriptions.stripe_status` values Stripe writes, for display.
 *
 * It sits beside `BillingStatus` so the two speak one badge vocabulary and stay
 * under static analysis: colours here must keep agreeing with
 * `BillingStatus::getColor()`, and `packages/SystemAdmin` is excluded from
 * PHPStan, so an exhaustive `match` living there would be unchecked.
 *
 * This is a Stripe status, not a Relaticle billing status. It says what the
 * subscription row holds; `BillingStatus` says what the workspace is entitled
 * to and why. A workspace can read `Pro` while its subscription reads
 * `past_due`, because this app calls `Cashier::keepPastDueSubscriptionsActive()`.
 */
enum StripeSubscriptionStatus: string implements HasColor, HasDescription, HasLabel
{
    case Active = 'active';

    case Trialing = 'trialing';

    case PastDue = 'past_due';

    case Unpaid = 'unpaid';

    case Canceled = 'canceled';

    case Incomplete = 'incomplete';

    case IncompleteExpired = 'incomplete_expired';

    case Paused = 'paused';

    /**
     * The badge state for a raw `stripe_status`. A value Stripe adds later
     * falls through as its own string rather than throwing, so the panel keeps
     * rendering what the database holds.
     */
    public static function forDisplay(string $status): self|string
    {
        return self::tryFrom($status) ?? $status;
    }

    /**
     * The tooltip for whatever forDisplay() returned. An unrecognised status
     * has nothing to explain.
     */
    public static function tooltipFor(self|string $state): ?string
    {
        return $state instanceof self ? $state->getDescription() : null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Trialing => 'Trialing',
            self::PastDue => 'Past due',
            self::Unpaid => 'Unpaid',
            self::Canceled => 'Canceled',
            self::Incomplete => 'Incomplete',
            self::IncompleteExpired => 'Incomplete expired',
            self::Paused => 'Paused',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Active => 'Paid and current. The latest invoice was charged successfully.',
            self::Trialing => 'Inside a Stripe trial. Nothing has been charged yet.',
            self::PastDue => 'The latest invoice failed. Stripe is retrying, and access stays open until it gives up.',
            self::Unpaid => 'Stripe exhausted its retries and stopped trying. Access is gone.',
            self::Canceled => 'Ended. No further invoices will be raised.',
            self::Incomplete => 'The first payment never completed, so the subscription never started.',
            self::IncompleteExpired => 'The first payment was abandoned and Stripe expired the attempt.',
            self::Paused => 'Paused at the end of a trial that collected no payment method.',
        };
    }

    /**
     * Kept in step with `BillingStatus::getColor()`: past due is danger on both
     * sides, an unstarted or ended subscription is grey rather than alarming.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trialing => 'info',
            self::PastDue, self::Unpaid => 'danger',
            self::Paused => 'warning',
            self::Canceled, self::Incomplete, self::IncompleteExpired => 'gray',
        };
    }
}
