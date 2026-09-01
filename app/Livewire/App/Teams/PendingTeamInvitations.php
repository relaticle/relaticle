<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\ResendTeamInvitation;
use App\Actions\Jetstream\RevokeTeamInvitation;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\TeamInvitation;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * No per-invitation copy-link: issueToken() stores only a hash, so copying could
 * only re-mint and invalidate the link already in the invitee's inbox.
 */
final class PendingTeamInvitations extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    #[On('teamInvitationSent')]
    public function refreshInvitations(): void
    {
        $this->resetTable();
    }

    public function hasPendingInvitations(): bool
    {
        return $this->team->teamInvitations()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->team->teamInvitations()->getQuery()->latest())
            ->paginated(false)
            // Split rather than discrete columns, matching the members list: a
            // header row over two fields reads as table chrome around a list.
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('email')
                        ->icon('heroicon-m-envelope')
                        ->iconColor('gray')
                        ->wrap(),
                    Tables\Columns\TextColumn::make('role')
                        ->badge()
                        ->color('gray')
                        ->grow(false)
                        ->formatStateUsing(fn (string $state): string => TeamRole::label($state)),
                    Tables\Columns\TextColumn::make('expires_at')
                        ->badge()
                        ->grow(false)
                        ->color(fn (TeamInvitation $record): string => $record->isExpired() ? 'danger' : 'warning')
                        ->formatStateUsing(fn (?Carbon $state): string => match (true) {
                            ! $state instanceof Carbon => __('teams.table.expired'),
                            $state->isPast() => __('teams.table.expired'),
                            default => __('teams.table.expires_in', ['time' => $state->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]),
                        }),
                ]),
            ])
            ->recordActions([
                $this->resendTeamInvitationAction(),
                $this->revokeTeamInvitationAction(),
            ]);
    }

    private function resendTeamInvitationAction(): Action
    {
        return Action::make('resendTeamInvitation')
            ->label(__('teams.actions.resend_team_invitation'))
            ->requiresConfirmation()
            ->visible(fn (): bool => Gate::check('updateTeamMember', $this->team))
            ->action(function (TeamInvitation $record): void {
                $this->authorizeInvitation($record, 'updateTeamMember');

                $key = "resend-invitation:{$record->getKey()}";

                if (RateLimiter::tooManyAttempts($key, 1)) {
                    $this->sendNotification(__('teams.notifications.resend_throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]), type: 'warning');

                    return;
                }

                RateLimiter::hit($key, 60);

                resolve(ResendTeamInvitation::class)->resend($record);

                $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
                $this->resetTable();
            });
    }

    private function revokeTeamInvitationAction(): Action
    {
        return Action::make('revokeTeamInvitation')
            ->label(__('teams.actions.revoke_team_invitation'))
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => Gate::check('removeTeamMember', $this->team))
            ->action(function (TeamInvitation $record): void {
                $this->authorizeInvitation($record, 'removeTeamMember');

                resolve(RevokeTeamInvitation::class)->revoke($record);

                $this->sendNotification(__('teams.notifications.team_invitation_revoked.success'));
                $this->resetTable();
            });
    }

    // The record key arrives from the client, so the team boundary is re-asserted
    // here and a foreign invitation is a 403 rather than a silent no-op.
    private function authorizeInvitation(TeamInvitation $invitation, string $ability): void
    {
        Gate::authorize($ability, $this->team);

        abort_unless($invitation->team_id === $this->team->id, 403);
    }

    public function render(): View
    {
        return view('livewire.app.teams.pending-team-invitations');
    }
}
