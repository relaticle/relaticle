<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\AcceptWorkspaceInvitation;
use App\Actions\Jetstream\DeclineWorkspaceInvitation;
use App\Livewire\BaseLivewireComponent;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property Collection<int, WorkspaceInvitation> $invitations
 */
final class PendingInvitationsForUser extends BaseLivewireComponent
{
    /**
     * @return Collection<int, WorkspaceInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return WorkspaceInvitation::query()
            ->with(['workspace', 'inviter'])
            ->whereRaw('lower(email) = ?', [Str::lower($this->authUser()->email)])
            ->where('expires_at', '>', now())
            ->get();
    }

    public function accept(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if (! $invitation instanceof WorkspaceInvitation) {
            return;
        }

        $user = $this->authUser();

        abort_unless(Str::lower($user->email) === Str::lower($invitation->email), 403);

        try {
            $workspace = resolve(AcceptWorkspaceInvitation::class)->execute($user, $invitation);
        } catch (HttpException $exception) {
            $this->sendNotification($exception->getMessage(), type: 'danger');

            return;
        }

        unset($this->invitations);

        // Filament's ambient tenant still points at the previous page's workspace, so
        // getHomeUrl() cannot resolve {tenant} until it is primed.
        Filament::setTenant($workspace, isQuiet: true);

        $this->sendNotification(__('workspaces.accept.joined', ['workspace' => $workspace->name]));

        $this->redirect(Filament::getHomeUrl());
    }

    public function decline(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if (! $invitation instanceof WorkspaceInvitation) {
            return;
        }

        resolve(DeclineWorkspaceInvitation::class)->decline($this->authUser(), $invitation);

        unset($this->invitations);

        $this->sendNotification(__('workspaces.pending_for_user.declined'));
    }

    // Resolved only against the collection already scoped to the user's email.
    private function ownedInvitation(string $invitationId): ?WorkspaceInvitation
    {
        return $this->invitations->firstWhere('id', $invitationId);
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.pending-invitations-for-user');
    }
}
