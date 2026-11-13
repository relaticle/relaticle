<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class SavedViewPolicy
{
    use HandlesAuthorization;

    public function view(User $user, SavedView $view): bool
    {
        return $user->belongsToWorkspaceId($view->workspace_id)
            && $view->user_id === $user->getKey();
    }

    public function update(User $user, SavedView $view): bool
    {
        return $this->view($user, $view);
    }

    public function delete(User $user, SavedView $view): bool
    {
        return $this->view($user, $view);
    }
}
