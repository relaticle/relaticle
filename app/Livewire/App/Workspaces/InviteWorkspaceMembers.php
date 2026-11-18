<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Livewire\App\Workspaces\Concerns\InvitesWorkspaceMembers;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

final class InviteWorkspaceMembers extends BaseLivewireComponent
{
    use InvitesWorkspaceMembers;

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('workspaces.sections.add_workspace_member.title'))
                ->aside()
                ->visible(fn (): bool => Gate::check('addWorkspaceMember', $this->workspace))
                ->description(__('workspaces.sections.add_workspace_member.description'))
                ->schema([
                    Actions::make([
                        $this->invitePeopleAction(),
                    ]),
                ]),
        ]);
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.invite-workspace-members');
    }
}
