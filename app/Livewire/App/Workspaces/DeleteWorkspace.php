<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\CancelWorkspaceDeletion;
use App\Actions\Jetstream\ScheduleWorkspaceDeletion;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

final class DeleteWorkspace extends BaseLivewireComponent
{
    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('workspaces.sections.delete_workspace.title'))
                    ->description(__('workspaces.sections.delete_workspace.description'))
                    ->aside()
                    ->visible(fn () => Gate::check('delete', $this->workspace))
                    ->schema([
                        TextEntry::make('notice')
                            ->hiddenLabel()
                            ->state(fn (): string => $this->workspace->isScheduledForDeletion()
                                ? __('workspaces.sections.delete_workspace.scheduled_notice', ['date' => $this->workspace->scheduled_deletion_at->format('F j, Y')])
                                : __('workspaces.sections.delete_workspace.notice')),
                        Actions::make([
                            Action::make('scheduleWorkspaceDeletionAction')
                                ->label(__('workspaces.actions.delete_workspace'))
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading(__('workspaces.sections.delete_workspace.title'))
                                ->modalDescription(fn (): string => __('workspaces.modals.delete_workspace.notice')
                                    .($this->workspace->subscribed() ? ' '.__('billing.deletion_notice') : ''))
                                ->modalSubmitActionLabel(__('workspaces.actions.delete_workspace'))
                                ->modalCancelAction(false)
                                ->visible(fn (): bool => ! $this->workspace->isScheduledForDeletion())
                                ->action(fn () => $this->deleteWorkspace($this->workspace)),
                            Action::make('cancelDeletionAction')
                                ->label(__('workspaces.actions.cancel_deletion'))
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalHeading(__('workspaces.modals.cancel_deletion.heading'))
                                ->modalDescription(__('workspaces.modals.cancel_deletion.notice'))
                                ->visible(fn (): bool => $this->workspace->isScheduledForDeletion())
                                ->action(fn () => $this->cancelWorkspaceDeletion($this->workspace)),
                        ]),
                    ]),
            ]);
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.delete-workspace');
    }

    public function deleteWorkspace(Workspace $workspace): void
    {
        try {
            resolve(ScheduleWorkspaceDeletion::class)->schedule($this->authUser(), $workspace);

            $this->sendNotification("Workspace scheduled for deletion on {$workspace->refresh()->scheduled_deletion_at->format('F j, Y')}");
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('workspaces.notifications.permission_denied.cannot_delete_workspace'),
                type: 'danger'
            );
        } catch (ValidationException $e) {
            $this->addError('workspace', $e->validator->errors()->first());
        }
    }

    public function cancelWorkspaceDeletion(Workspace $workspace): void
    {
        try {
            resolve(CancelWorkspaceDeletion::class)->cancel($this->authUser(), $workspace);

            $this->sendNotification('Workspace deletion cancelled');
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('workspaces.notifications.permission_denied.cannot_cancel_workspace_deletion'),
                type: 'danger'
            );
        }
    }
}
