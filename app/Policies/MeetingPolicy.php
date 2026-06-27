<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->belongsToTeam($meeting->team);
    }
}
