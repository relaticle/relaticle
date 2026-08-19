<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\AcceptTeamInvitation;
use App\Actions\Jetstream\DeclineTeamInvitation;
use App\Livewire\BaseLivewireComponent;
use App\Models\TeamInvitation;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Surfaces invitations for the signed-in user's email, even when they registered
 * independently and never followed the invite link. Consent stays explicit: this
 * never auto-joins, it only lists invitations the user can choose to accept or
 * decline.
 *
 * @property Collection<int, TeamInvitation> $invitations
 */
final class PendingInvitationsForUser extends BaseLivewireComponent
{
    /**
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return TeamInvitation::query()
            ->with('team')
            ->whereRaw('lower(email) = ?', [Str::lower($this->authUser()->email)])
            ->where('expires_at', '>', now())
            ->get();
    }

    public function accept(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if (! $invitation instanceof TeamInvitation) {
            return;
        }

        $user = $this->authUser();

        abort_unless(Str::lower($user->email) === Str::lower($invitation->email), 403);

        try {
            $team = resolve(AcceptTeamInvitation::class)->execute($user, $invitation);
        } catch (HttpException $exception) {
            $this->sendNotification($exception->getMessage(), type: 'danger');

            return;
        }

        unset($this->invitations);

        /**
         * The action already switched current_team_id in the database; Filament's
         * ambient tenant still reflects whatever team (if any) the current page was
         * bound to, so getHomeUrl() would otherwise fail to resolve the {tenant}
         * route parameter (or send the user back to a team they didn't just join).
         * isQuiet avoids re-triggering the SwitchTeam listener, which already ran
         * inside the action.
         */
        Filament::setTenant($team, isQuiet: true);

        $this->sendNotification(__('teams.accept.joined', ['team' => $team->name]));

        $this->redirect(Filament::getHomeUrl());
    }

    public function decline(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if (! $invitation instanceof TeamInvitation) {
            return;
        }

        resolve(DeclineTeamInvitation::class)->decline($this->authUser(), $invitation);

        unset($this->invitations);

        $this->sendNotification(__('teams.pending_for_user.declined'));
    }

    /**
     * The client-supplied id is never trusted alone — it is only ever resolved
     * against the collection already scoped to the signed-in user's email.
     */
    private function ownedInvitation(string $invitationId): ?TeamInvitation
    {
        return $this->invitations->firstWhere('id', $invitationId);
    }

    public function render(): View
    {
        return view('livewire.app.teams.pending-invitations-for-user');
    }
}
