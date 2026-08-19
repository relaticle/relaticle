<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\ResendTeamInvitation;
use App\Actions\Jetstream\RevokeTeamInvitation;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\TeamInvitation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\On;

/**
 * The outstanding-invitation worklist: short, unpaginated, and hidden entirely
 * when empty, so every pending invitation is always one glance away. Twenty
 * (`SettingsWorkspaceMembersInviteTab`) and Slack both render it this way; the
 * merged, paginated people table this replaced could bury a pending invitation
 * on page three.
 *
 * There is deliberately no per-invitation "copy link" action. The raw token is
 * only ever held in memory at mint time — `TeamInvitation::issueToken()` stores
 * a SHA-256 hash — so a copy action could only work by re-minting, which would
 * silently invalidate the link already sitting in the invitee's inbox. Twenty
 * excludes the token from its listing query for the same reason, and Slack and
 * Notion offer no per-invitation link either. Sharing a link out-of-band is
 * what the workspace-wide invite link (TeamMembers::manageInviteLinkAction) is
 * for; re-delivering this specific one is what Resend is for.
 */
final class PendingTeamInvitations extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

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
            ->heading(__('teams.table.pending_heading'))
            ->description(fn (): string => trans_choice(
                'teams.table.pending_count',
                $this->team->teamInvitations()->count(),
            ))
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('teams.table.email'))
                    ->icon('heroicon-m-envelope')
                    ->iconColor('gray')
                    // Wrapped rather than truncated: a long address is the whole
                    // identity of an invitation row, and on a phone the untruncated
                    // column is what pushes the actions menu off screen.
                    ->wrap(),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('teams.table.role'))
                    ->badge()
                    ->color('gray')
                    // On a phone the expiry drives the decision (resend or not),
                    // so the role yields the space to keep it on screen.
                    ->visibleFrom('md')
                    ->formatStateUsing(fn (string $state): string => $this->roleLabel($state)),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('teams.table.expires'))
                    ->badge()
                    ->color(fn (TeamInvitation $record): string => $record->isExpired() ? 'danger' : 'warning')
                    ->formatStateUsing(fn (?Carbon $state): string => match (true) {
                        ! $state instanceof Carbon => __('teams.table.expired'),
                        $state->isPast() => __('teams.table.expired'),
                        default => $state->diffForHumans(),
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->resendTeamInvitationAction(),
                    $this->revokeTeamInvitationAction(),
                ]),
            ]);
    }

    /**
     * Falls back to the raw role string for a legacy or unregistered role key
     * rather than throwing — Jetstream::findRole()'s untyped PHPDoc return
     * makes PHPStan misjudge a plain `?->` chain as never-null here.
     */
    private function roleLabel(string $role): string
    {
        $registeredRole = Jetstream::findRole($role);

        if ($registeredRole === null) {
            return $role;
        }

        return $registeredRole->name;
    }

    private function resendTeamInvitationAction(): Action
    {
        return Action::make('resendTeamInvitation')
            ->label(__('teams.actions.resend_team_invitation'))
            ->icon('heroicon-m-arrow-path')
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
            ->icon('heroicon-m-x-mark')
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

    /**
     * The table query is already team-scoped, so a foreign key cannot resolve to
     * a record here. This re-asserts the boundary anyway: the record key arrives
     * from the client, and PendingTeamInvitationsCrossTenantTest pins a 403
     * rather than a silent no-op as the contract for a foreign invitation.
     */
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
