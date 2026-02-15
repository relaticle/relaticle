<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\DeleteTeam as DeleteTeamAction;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
                            ->state(__('teams.sections.delete_team.notice')),
                        Actions::make([
                            Action::make('deleteAccountAction')
                                ->label(__('teams.actions.delete_team'))
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading(__('teams.sections.delete_team.title'))
                                ->modalDescription(__('teams.modals.delete_team.notice'))
                                ->modalSubmitActionLabel(__('teams.actions.delete_team'))
                                ->modalCancelAction(false)
                                ->action(fn () => $this->deleteTeam($this->team)),
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
        resolve(DeleteTeamAction::class)->delete($team);

        Filament::setTenant(Auth::guard('web')->user()->personalTeam());

        $this->sendNotification(__('teams.notifications.team_deleted.success'));

        redirect()->to(Filament::getCurrentPanel()?->getHomeUrl());
    }
}
