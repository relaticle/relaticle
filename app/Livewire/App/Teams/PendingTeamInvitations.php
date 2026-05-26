<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\ResendTeamInvitation;
use App\Actions\Jetstream\RevokeTeamInvitation;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

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
                Action::make('revokeTeamInvitation')
                    ->label(__('teams.actions.revoke_team_invitation'))
                    ->color('danger')
                    ->visible(fn () => Gate::check('removeTeamMember', $this->team))
                    ->requiresConfirmation()
                    ->action(fn (Model $record) => $this->revokeTeamInvitation($record)),
            ]);
    }

    public function copyInviteLink(Model $invitation): void
    {
        /** @var TeamInvitation $invitation */
        Gate::authorize('updateTeamMember', $this->team);

        abort_unless($invitation->team_id === $this->team->id, 403);

        $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

        $this->sendNotification(__('teams.notifications.invite_link_copied.success'));
    }

    public function resendTeamInvitation(Model $invitation): void
    {
        /** @var TeamInvitation $invitation */
        Gate::authorize('updateTeamMember', $this->team);

        abort_unless($invitation->team_id === $this->team->id, 403);

        $key = "resend-invitation:{$invitation->getKey()}";

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            $this->sendNotification(__('Please wait :seconds seconds before resending.', ['seconds' => $seconds]));

            return;
        }

        RateLimiter::hit($key, 60);

        resolve(ResendTeamInvitation::class)->resend($invitation);

        $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
    }

    public function revokeTeamInvitation(Model $invitation): void
    {
        /** @var TeamInvitation $invitation */
        Gate::authorize('removeTeamMember', $this->team);

        abort_unless($invitation->team_id === $this->team->id, 403);

        resolve(RevokeTeamInvitation::class)->revoke($invitation);

        $this->sendNotification(__('teams.notifications.team_invitation_revoked.success'));
    }

    public function render(): View
    {
        return view('livewire.app.teams.pending-team-invitations');
    }
}
