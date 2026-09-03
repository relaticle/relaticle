<?php

declare(strict_types=1);

namespace App\Policies;

use App\Features\EmailIntegration;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        if (! Feature::active(EmailIntegration::class)) {
            return false;
        }

        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->belongsToTeamId($meeting->team_id);
    }
}
