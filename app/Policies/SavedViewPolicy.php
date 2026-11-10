<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class SavedViewPolicy
{
    use HandlesAuthorization;

    public function view(User $user, SavedView $view): bool
    {
        return $user->belongsToWorkspaceId($view->workspace_id)
            && ($this->owns($user, $view) || $view->is_shared);
    }

    public function update(User $user, SavedView $view): bool
    {
        return $user->belongsToWorkspaceId($view->workspace_id)
            && ($this->owns($user, $view) || ($view->is_shared && $user->hasWorkspaceCapability($view->workspace_id, WorkspaceCapability::ViewsManage)));
    }

    public function delete(User $user, SavedView $view): bool
    {
        return $this->update($user, $view);
    }

    private function owns(User $user, SavedView $view): bool
    {
        return $view->user_id === $user->getKey();
    }
}
