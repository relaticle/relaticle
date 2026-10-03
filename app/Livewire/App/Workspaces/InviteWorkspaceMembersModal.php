<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Livewire\App\Workspaces\Concerns\InvitesWorkspaceMembers;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

final class InviteWorkspaceMembersModal extends BaseLivewireComponent
{
    use InvitesWorkspaceMembers;

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    #[On('open-invite-workspace-members')]
    public function open(): void
    {
        $this->mountAction('invitePeople');
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.invite-workspace-members-modal');
    }
}
