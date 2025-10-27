<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;

final class PendingTeamInvitations extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->team->teamInvitations()->latest())
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('email'),
                ]),
            ])
            ->paginated(false)
            ->recordActions([
                Action::make('resendTeamInvitation')
                    ->label(__('teams.actions.resend_team_invitation'))
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn () => Gate::check('updateTeamMember', $this->team))
                    ->action($this->resendTeamInvitation(...)),
                Action::make('cancelTeamInvitation')
                    ->label(__('teams.actions.cancel_team_invitation'))
                    ->color('danger')
                    ->visible(fn () => Gate::check('removeTeamMember', $this->team))
                    ->requiresConfirmation()
                    ->action(fn (Model $record) => $this->cancelTeamInvitation($this->team, $record)),
            ]);
    }

    public function resendTeamInvitation(Model $invitation): void
    {
        /** @var TeamInvitationModel $invitation */
        Mail::to($invitation->email)->send(new TeamInvitation($invitation));

        $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
    }

    public function cancelTeamInvitation(Team $team, Model $invitation): void
    {
        $invitation->delete();

        $team->fresh();

        $this->sendNotification(__('teams.notifications.team_invitation_cancelled.success'));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.app.teams.pending-team-invitations');
    }
}
