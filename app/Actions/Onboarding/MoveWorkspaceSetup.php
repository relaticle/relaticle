<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\User;
use App\Models\Workspace;

final readonly class MoveWorkspaceSetup
{
    public function execute(User $user, Workspace $workspace, OnboardingStep $from, ?OnboardingStep $to): bool
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        $moved = Workspace::query()
            ->whereKey($workspace->getKey())
            ->where('onboarding_step', $from)
            ->update(['onboarding_step' => $to, 'updated_at' => now()]) === 1;

        $workspace->refresh();

        return $moved;
    }
}
