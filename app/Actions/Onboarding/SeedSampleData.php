<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Laravel\Pennant\Feature;
use Relaticle\OnboardSeed\OnboardSeeder;

final readonly class SeedSampleData
{
    public function __construct(
        private OnboardSeeder $onboardSeeder,
        private WorkspaceActivationFacts $facts,
    ) {}

    public function execute(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        if (! $workspace->isPersonalWorkspace() || ! Feature::active(OnboardSeed::class)) {
            return;
        }

        if ($this->facts->hasSampleData($workspace) || $this->facts->hasOwnRecord($workspace)) {
            return;
        }

        $useCase = $workspace->onboarding_use_case ?? OnboardingUseCase::Other;

        $this->onboardSeeder->run($user, $workspace, $useCase->getFixtureSet());

        $this->facts->forget($workspace);
    }
}
