<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Team;
use App\Models\User;

final readonly class DismissActivationChecklist
{
    public function execute(User $user, Team $team): void
    {
        abort_unless($user->can('update', $team), 403);

        $team->update(['activation_checklist_dismissed_at' => now()]);
    }
}
