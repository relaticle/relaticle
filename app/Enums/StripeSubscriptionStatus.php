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

    /** @return list<string> */
    public static function neverGranted(): array
    {
        return [self::Incomplete->value, self::IncompleteExpired->value];
    }

    /** @return list<string> */
    public static function live(): array
    {
        return [self::Active->value, self::PastDue->value, self::Trialing->value];
    }

    /** @return list<string> */
    public static function ended(): array
    {
        return [self::Canceled->value, self::IncompleteExpired->value];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('enums/stripe-subscription-status.active'),
            self::Trialing => __('enums/stripe-subscription-status.trialing'),
            self::PastDue => __('enums/stripe-subscription-status.past_due'),
            self::Unpaid => __('enums/stripe-subscription-status.unpaid'),
            self::Canceled => __('enums/stripe-subscription-status.canceled'),
            self::Incomplete => __('enums/stripe-subscription-status.incomplete'),
            self::IncompleteExpired => __('enums/stripe-subscription-status.incomplete_expired'),
            self::Paused => __('enums/stripe-subscription-status.paused'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Active => __('enums/stripe-subscription-status.descriptions.active'),
            self::Trialing => __('enums/stripe-subscription-status.descriptions.trialing'),
            self::PastDue => __('enums/stripe-subscription-status.descriptions.past_due'),
            self::Unpaid => __('enums/stripe-subscription-status.descriptions.unpaid'),
            self::Canceled => __('enums/stripe-subscription-status.descriptions.canceled'),
            self::Incomplete => __('enums/stripe-subscription-status.descriptions.incomplete'),
            self::IncompleteExpired => __('enums/stripe-subscription-status.descriptions.incomplete_expired'),
            self::Paused => __('enums/stripe-subscription-status.descriptions.paused'),
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
