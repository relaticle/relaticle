<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\AcceptWorkspaceInvitation;
use App\Actions\Jetstream\DeclineWorkspaceInvitation;
use App\Livewire\BaseLivewireComponent;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property Collection<int, WorkspaceInvitation> $invitations
 */
final class PendingInvitationsForUser extends BaseLivewireComponent
{
    public const string MODAL_ID = 'pending-workspace-invitation';

    #[Locked]
    public bool $asksOnThisPage = false;

    public function mount(): void
    {
        $this->asksOnThisPage = $this->invitations->isNotEmpty();
    }

    /**
     * @return Collection<int, WorkspaceInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        if (! $this->authUser()->hasVerifiedEmail()) {
            return collect();
        }

        return WorkspaceInvitation::query()
            ->with(['workspace', 'inviter' => fn (BelongsTo $inviter): BelongsTo => $inviter->withoutGlobalScope(Filament::getTenancyScopeName())])
            ->whereRaw('lower(email) = ?', [Str::lower($this->authUser()->email)])
            ->unexpired()
            ->whereDoesntHave('workspace', fn (Builder $workspace): Builder => $workspace->scheduledForDeletion())
            ->oldest()
            ->get();
    }

    public function accept(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if (! $invitation instanceof WorkspaceInvitation) {
            $this->sendNotification(__('workspaces.accept.no_longer_valid'), type: 'danger');
            $this->closeWhenNoneLeft();

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
            $this->sendNotification(__('workspaces.accept.no_longer_valid'), type: 'danger');
            $this->closeWhenNoneLeft();

            return;
        }

        resolve(DeclineWorkspaceInvitation::class)->decline($this->authUser(), $invitation);

        $this->sendNotification(__('workspaces.pending_for_user.declined'));
        $this->closeWhenNoneLeft();
    }

    private function closeWhenNoneLeft(): void
    {
        unset($this->invitations);

        if ($this->invitations->isEmpty()) {
            $this->dispatch('close-modal', id: self::MODAL_ID);
        }
    }

    // Resolved only against the collection already scoped to the user's email.
    private function ownedInvitation(string $invitationId): ?WorkspaceInvitation
    {
        return $this->invitations->firstWhere('id', $invitationId);
    }

    public function render(): View
    {
        $invitation = $this->invitations->first();

        return view('livewire.app.workspaces.pending-invitations-for-user', [
            'invitation' => $invitation,
            'memberCount' => $invitation?->workspace->memberCount(),
        ]);
    }
}
