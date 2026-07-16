<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class EmailTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->belongsToTeam($template->team)
            && ($template->is_shared || $template->created_by === $user->getKey());
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $template->created_by === $user->getKey();
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return $template->created_by === $user->getKey();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function restore(User $user, EmailTemplate $template): bool
    {
        return $template->created_by === $user->getKey();
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function forceDelete(User $user, EmailTemplate $template): bool
    {
        return $template->created_by === $user->getKey();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }
}
