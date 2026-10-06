<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\User;
use App\Models\Workspace;

final readonly class MoveWorkspaceSetup
{
    public function execute(User $user, Workspace $workspace, ?OnboardingStep $step): void
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        $workspace->update(['onboarding_step' => $step]);
    }
}
