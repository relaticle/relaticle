<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\User;
use App\Models\Workspace;

final readonly class DismissSetupOpener
{
    public function execute(User $user, Workspace $workspace): void
    {
        abort_unless($user->can('update', $workspace), 403);

        $workspace->update(['onboarding_opener_dismissed_at' => now()]);
    }
}
