<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\CancelTeamDeletion;
use App\Actions\Jetstream\ScheduleTeamDeletion;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DeleteTeam extends BaseLivewireComponent
{
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('teams.sections.delete_team.title'))
                    ->description(__('teams.sections.delete_team.description'))
                    ->aside()
                    ->visible(fn () => Gate::check('delete', $this->team))
                    ->schema([
                        TextEntry::make('notice')
                            ->hiddenLabel()
                            ->state(fn (): string => $this->team->isScheduledForDeletion()
                                ? __('teams.sections.delete_team.scheduled_notice', ['date' => $this->team->scheduled_deletion_at->format('F j, Y')])
                                : __('teams.sections.delete_team.notice')),
                        Actions::make([
                            Action::make('scheduleTeamDeletionAction')
                                ->label(__('teams.actions.delete_team'))
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading(__('teams.sections.delete_team.title'))
                                ->modalDescription(__('teams.modals.delete_team.notice'))
                                ->modalSubmitActionLabel(__('teams.actions.delete_team'))
                                ->modalCancelAction(false)
                                ->visible(fn (): bool => ! $this->team->isScheduledForDeletion())
                                ->action(fn () => $this->deleteTeam($this->team)),
                            Action::make('cancelDeletionAction')
                                ->label(__('teams.actions.cancel_deletion'))
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalHeading(__('teams.modals.cancel_deletion.heading'))
                                ->modalDescription(__('teams.modals.cancel_deletion.notice'))
                                ->visible(fn (): bool => $this->team->isScheduledForDeletion())
                                ->action(fn () => $this->cancelTeamDeletion($this->team)),
                        ]),
                    ]),
            ]);
    }

    public function render(): View
    {
        return view('livewire.app.teams.delete-team');
    }

    public function deleteTeam(Team $team): void
    {
        try {
            resolve(ScheduleTeamDeletion::class)->schedule($this->authUser(), $team);

            $this->sendNotification("Team scheduled for deletion on {$team->refresh()->scheduled_deletion_at->format('F j, Y')}");
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('teams.notifications.permission_denied.cannot_delete_team'),
                type: 'danger'
            );
        } catch (ValidationException $e) {
            $this->addError('team', $e->validator->errors()->first());
        }
    }

    public function cancelTeamDeletion(Team $team): void
    {
        try {
            resolve(CancelTeamDeletion::class)->cancel($this->authUser(), $team);

            $this->sendNotification('Team deletion cancelled');
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('teams.notifications.permission_denied.cannot_cancel_team_deletion'),
                type: 'danger'
            );
        }
    }
}
