<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\TableLayout;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class TableLayoutPolicy
{
    use HandlesAuthorization;

    public function view(User $user, TableLayout $layout): bool
    {
        return $user->belongsToWorkspaceId($layout->workspace_id)
            && ($this->owns($user, $layout) || $layout->is_shared);
    }

    public function update(User $user, TableLayout $layout): bool
    {
        return $user->belongsToWorkspaceId($layout->workspace_id)
            && ($this->owns($user, $layout) || ($layout->is_shared && $user->hasWorkspaceCapability($layout->workspace_id, WorkspaceCapability::LayoutsManage)));
    }

    public function delete(User $user, TableLayout $layout): bool
    {
        return $this->update($user, $layout);
    }

    private function owns(User $user, TableLayout $layout): bool
    {
        return $layout->user_id === $user->getKey();
    }
}
