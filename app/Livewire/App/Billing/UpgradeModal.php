<?php

declare(strict_types=1);

namespace App\Livewire\App\Billing;

use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Relaticle\Cloud\Billing\Actions\CreateProCheckout;
use Throwable;

final class UpgradeModal extends Component
{
    public const string MODAL_ID = 'upgrade-plan';

    public function checkout(string $interval): void
    {
        $workspace = $this->workspace();

        if (! $workspace instanceof Workspace || ! $this->canUpgrade()) {
            return;
        }

        // Rejected here so any InvalidArgumentException escaping the action means
        // a missing price config, which must still reach Flare.
        if (! in_array($interval, CreateProCheckout::INTERVALS, true)) {
            $this->notifyCheckoutFailed();

            return;
        }

        try {
            $this->redirect(resolve(CreateProCheckout::class)->execute($workspace, $interval));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    public function canUpgrade(): bool
    {
        $workspace = $this->workspace();
        $user = Filament::auth()->user();

        return Feature::active(BillingFeature::class)
            && $workspace instanceof Workspace
            && $user instanceof User
            && $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)
            && ! $workspace->subscribed()
            && ! $workspace->plan->isManaged();
    }

    public function render(): View
    {
        return view('livewire.app.billing.upgrade-modal', ['workspace' => $this->workspace()]);
    }

    private function notifyCheckoutFailed(): void
    {
        Notification::make()
            ->title(__('billing.errors.checkout_failed'))
            ->danger()
            ->send();
    }

    private function workspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
