<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Str;

final readonly class DeclineWorkspaceInvitation
{
    public function decline(User $user, WorkspaceInvitation $invitation): void
    {
        abort_unless(
            Str::lower($user->email) === Str::lower($invitation->email),
            403,
        );

        $invitation->delete();
    }
}
