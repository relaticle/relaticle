<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Livewire\Concerns;

use App\Enums\Plan;
use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\Actions\CreateSubscriptionCheckout;
use Throwable;

trait StartsSubscriptionCheckout
{
    public function subscribe(string $plan, string $interval): void
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace || ! $this->canUpgrade()) {
            return;
        }

        $chosen = Plan::tryFrom($plan);

        if (! $chosen instanceof Plan || ! $chosen->isSelfServe() || ! in_array($interval, CreateSubscriptionCheckout::INTERVALS, true)) {
            $this->notifyCheckoutFailed();

            return;
        }

        try {
            $this->redirect(resolve(CreateSubscriptionCheckout::class)->execute($workspace, $chosen, $interval));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    public function canUpgrade(): bool
    {
        $workspace = Filament::getTenant();
        $user = Filament::auth()->user();

        return Feature::active(BillingFeature::class)
            && $workspace instanceof Workspace
            && $user instanceof User
            && $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)
            && ! $workspace->subscribed()
            && ! $workspace->plan->isManaged();
    }

    private function notifyCheckoutFailed(): void
    {
        Notification::make()
            ->title(__('cloud::billing.errors.checkout_failed'))
            ->danger()
            ->send();
    }
}
