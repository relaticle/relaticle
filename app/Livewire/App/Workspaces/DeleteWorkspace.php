<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\ScheduleWorkspaceDeletion;
use App\Contracts\Billing;
use App\Livewire\App\Workspaces\Concerns\CancelsWorkspaceDeletion;
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
    use CancelsWorkspaceDeletion;

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
                                ->modalDescription(fn (): string => collect([
                                    __('workspaces.modals.delete_workspace.notice'),
                                    resolve(Billing::class)->deletionNotice($this->workspace),
                                ])->filter()->implode(' '))
                                ->modalSubmitActionLabel(__('workspaces.actions.delete_workspace'))
                                ->modalCancelAction(false)
                                ->visible(fn (): bool => ! $this->workspace->isScheduledForDeletion())
                                ->action(fn () => $this->deleteWorkspace($this->workspace)),
                            $this->makeCancelDeletionAction()
                                ->color('gray')
                                ->visible(fn (): bool => $this->workspace->isScheduledForDeletion()),
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
            $this->reloadPage();
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('workspaces.notifications.permission_denied.cannot_delete_workspace'),
                type: 'danger'
            );
        } catch (ValidationException $e) {
            $this->addError('workspace', $e->validator->errors()->first());
        }
    }
}
