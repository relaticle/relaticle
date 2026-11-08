<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingDataSource;
use App\Models\User;
use App\Models\Workspace;

final readonly class SaveOnboardingDataSource
{
    public function __construct(private SeedSampleData $seedSamples) {}

    public function execute(User $user, Workspace $workspace, OnboardingDataSource $source): bool
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        if (! in_array($source, OnboardingDataSource::offered(), true)) {
            return false;
        }

        $stored = Workspace::query()
            ->whereKey($workspace->getKey())
            ->whereNull('onboarding_data_source')
            ->whereNull('onboarding_step')
            ->update(['onboarding_data_source' => $source, 'updated_at' => now()]) === 1;

        $workspace->refresh();

        if ($stored && $source->seedsSamples()) {
            $this->seedSamples->execute($user, $workspace);
        }

        return $stored;
    }
}
