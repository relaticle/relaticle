<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\User;
use App\Models\Workspace;

final readonly class DismissActivationChecklist
{
    public function execute(User $user, Workspace $workspace): void
    {
        abort_unless($user->can('update', $workspace), 403);

        $workspace->update(['activation_checklist_dismissed_at' => now()]);
    }
}
