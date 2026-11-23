<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces\Concerns;

use App\Actions\Jetstream\CancelWorkspaceDeletion;
use App\Contracts\Billing;
use App\Data\SubscriptionEnding;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

trait CancelsWorkspaceDeletion
{
    public function cancelWorkspaceDeletion(): void
    {
        try {
            resolve(CancelWorkspaceDeletion::class)->cancel($this->authUser(), $this->workspace);
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('workspaces.notifications.permission_denied.cannot_cancel_workspace_deletion'),
                type: 'danger'
            );

            return;
        }

        $this->notifyDeletionCancelled(resolve(Billing::class)->subscriptionEnding($this->workspace->refresh()));
        $this->reloadPage();
    }

    protected function makeCancelDeletionAction(): Action
    {
        return Action::make('cancelDeletion')
            ->label(__('workspaces.actions.cancel_deletion'))
            ->requiresConfirmation()
            ->modalHeading(__('workspaces.modals.cancel_deletion.heading'))
            ->modalDescription(__('workspaces.modals.cancel_deletion.notice'))
            ->modalIcon('lucide-undo-2')
            ->modalIconColor('primary')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary'))
            ->action(fn () => $this->cancelWorkspaceDeletion());
    }

    private function notifyDeletionCancelled(?SubscriptionEnding $ending): void
    {
        $notification = Notification::make()
            ->title(__('workspaces.notifications.workspace_deletion_cancelled.success'))
            ->success();

        if ($ending instanceof SubscriptionEnding) {
            $notification->body($ending->notice)->persistent()->actions([
                Action::make('keepSubscription')->label($ending->keepLabel)->url($ending->keepUrl)->button(),
            ]);
        }

        $notification->send();
    }

    // The deletion banner sits outside the component that changed the state,
    // so only a page load redraws it.
    private function reloadPage(): void
    {
        $this->redirect(Livewire::originalUrl(), navigate: true);
    }
}
