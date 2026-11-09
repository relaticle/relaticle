<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Enums\WorkspaceCapability;
use App\Livewire\App\Workspaces\Concerns\CancelsWorkspaceDeletion;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

final class WorkspaceDeletionBanner extends BaseLivewireComponent
{
    use CancelsWorkspaceDeletion;

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function cancelDeletionAction(): Action
    {
        return $this->makeCancelDeletionAction()
            ->link()
            ->color('danger')
            ->size(Size::ExtraSmall)
            ->extraAttributes(['class' => 'shrink-0'])
            ->visible(fn (): bool => $this->authUser()->hasWorkspaceCapability($this->workspace->getKey(), WorkspaceCapability::WorkspaceManage));
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.workspace-deletion-banner');
    }
}
