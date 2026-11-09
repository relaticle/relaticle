<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces\Concerns;

use App\Actions\Jetstream\CancelWorkspaceDeletion;
use Filament\Actions\Action;
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

        $this->sendNotification('Workspace deletion cancelled');
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

    // The deletion banner sits outside the component that changed the state,
    // so only a page load redraws it.
    private function reloadPage(): void
    {
        $this->redirect(Livewire::originalUrl(), navigate: true);
    }
}
