<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class DeclineTeamInvitation
{
    public function decline(User $user, TeamInvitation $invitation): void
    {
        abort_unless(
            Str::lower($user->email) === Str::lower($invitation->email),
            403,
        );

        $invitation->delete();
    }
}
