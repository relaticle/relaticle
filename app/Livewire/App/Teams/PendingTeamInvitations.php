<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\TeamInvitation;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

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
                    Tables\Columns\TextColumn::make('expires_at')
                        ->label(__('Expires'))
                        ->state(function (TeamInvitation $record): string {
                            if ($record->isExpired()) {
                                return __('Expired');
                            }

                            /** @var Carbon $expiresAt */
                            $expiresAt = $record->expires_at;

                            return $expiresAt->diffForHumans();
                        }),
                ]),
            ])
            ->paginated(false)
            ->recordActions([
                Action::make('extendTeamInvitation')
                    ->label(__('teams.actions.extend_team_invitation'))
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn () => Gate::check('updateTeamMember', $this->team))
                    ->action(fn (Model $record) => $this->extendTeamInvitation($record)),
                Action::make('copyInviteLink')
                    ->label(__('teams.actions.copy_invite_link'))
                    ->color('gray')
                    ->visible(fn () => Gate::check('updateTeamMember', $this->team))
                    ->action(fn (Model $record) => $this->copyInviteLink($record)),
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

    public function extendTeamInvitation(Model $invitation): void
    {
        Gate::authorize('updateTeamMember', $this->team);

        $expiryDays = (int) config('jetstream.invitation_expiry_days', 7);

        $invitation->update([
            'expires_at' => now()->addDays($expiryDays),
        ]);

        $this->sendNotification(__('teams.notifications.team_invitation_extended.success'));
    }

    public function copyInviteLink(Model $invitation): void
    {
        Gate::authorize('updateTeamMember', $this->team);

        $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

        $this->sendNotification(__('teams.notifications.invite_link_copied.success'));
    }

    public function resendTeamInvitation(Model $invitation): void
    {
        Gate::authorize('updateTeamMember', $this->team);

        $key = "resend-invitation:{$invitation->getKey()}";

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            $this->sendNotification(__('Please wait :seconds seconds before resending.', ['seconds' => $seconds]));

            return;
        }

        RateLimiter::hit($key, 60);

        /** @var \Laravel\Jetstream\TeamInvitation $invitation */
        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));

        $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
    }

    public function cancelTeamInvitation(Team $team, Model $invitation): void
    {
        $invitation->delete();

        $team->fresh();

        $this->sendNotification(__('teams.notifications.team_invitation_cancelled.success'));
    }

    public function render(): View
    {
        return view('livewire.app.teams.pending-team-invitations');
    }
}
