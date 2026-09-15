<?php

declare(strict_types=1);

namespace App\Livewire\App\Billing;

use App\Actions\Billing\CreateProCheckout;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Throwable;

/**
 * Card entry for a Pro upgrade, rendered over the app instead of on a
 * Stripe-hosted page. The frame inside is Stripe's; everything around it,
 * including the billing period, is ours.
 */
final class UpgradeModal extends Component
{
    public const string MODAL_ID = 'upgrade-plan';

    /** Stripe prices a session at creation, so changing this remounts the frame. */
    public string $interval = 'yearly';

    public bool $paid = false;

    public ?string $error = null;

    /**
     * Stripe hosts the card fields, so this covers session-creation looping
     * rather than card testing.
     */
    private const int MAX_SESSIONS = 10;

    private const int DECAY_SECONDS = 600;

    public function createSession(string $interval, string $theme): ?string
    {
        $this->error = null;

        $workspace = $this->workspace();

        if (! $workspace instanceof Workspace || ! $this->canUpgrade()) {
            return null;
        }

        // Rejected here so any InvalidArgumentException escaping the action means
        // a missing price config, which must still reach Flare.
        if (! in_array($interval, CreateProCheckout::INTERVALS, true)) {
            $this->error = __('billing.errors.checkout_failed');

            return null;
        }

        $key = "upgrade-session:{$workspace->getKey()}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_SESSIONS)) {
            $this->error = __('billing.upgrade.rate_limited', [
                'seconds' => RateLimiter::availableIn($key),
            ]);

            return null;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        try {
            $secret = resolve(CreateProCheckout::class)->execute($workspace, $interval, $theme);
        } catch (Throwable $exception) {
            report($exception);
            $this->error = __('billing.errors.checkout_failed');

            return null;
        }

        $this->interval = $interval;

        return $secret;
    }

    public function markPaid(): void
    {
        $this->paid = true;
    }

    /**
     * The local subscription row is written by the customer.subscription.created
     * webhook, not by the session, so the modal can outrun it.
     */
    public function activated(): bool
    {
        return $this->workspace()?->subscribed() === true;
    }

    public function canUpgrade(): bool
    {
        $workspace = $this->workspace();
        $user = Filament::auth()->user();

        return Feature::active(BillingFeature::class)
            && $workspace instanceof Workspace
            && $user instanceof User
            && $user->ownsWorkspace($workspace)
            && ! $workspace->subscribed()
            && $workspace->plan !== Plan::Enterprise;
    }

    public function render(): View
    {
        return view('livewire.app.billing.upgrade-modal');
    }

    private function workspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
