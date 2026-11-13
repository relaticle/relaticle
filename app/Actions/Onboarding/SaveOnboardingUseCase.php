<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceSetupStep;
use App\Features\OnboardSeed;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Pennant\Feature;
use Relaticle\OnboardSeed\OnboardSeeder;

final readonly class SaveOnboardingUseCase
{
    public function __construct(
        private ApplyStagePreset $applyStagePreset,
        private OnboardSeeder $onboardSeeder,
        private WorkspaceActivationFacts $facts,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $user, Workspace $workspace, array $input, ?WorkspaceSetupStep $then): bool
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        $this->validate($input);

        $useCase = OnboardingUseCase::from((string) $input['onboarding_use_case']);

        $saved = DB::transaction(function () use ($workspace, $input, $useCase, $then): bool {
            $locked = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->sole();

            if ($locked->onboarding_step !== WorkspaceSetupStep::UseCase || $locked->onboarding_use_case !== null) {
                return false;
            }

            $locked->update([
                'onboarding_use_case' => $useCase,
                'onboarding_context' => $useCase->getSubOptions() === []
                    ? null
                    : array_values($input['onboarding_context']),
                'onboarding_other_use_case' => $useCase === OnboardingUseCase::Other
                    ? ($input['onboarding_other_use_case'] ?? null)
                    : null,
                'onboarding_step' => $then,
            ]);

            $preset = $useCase->stagePreset();

            if ($preset !== null) {
                $this->applyStagePreset->execute($locked, $preset);
            }

            return true;
        });

        $workspace->refresh();

        if (! $saved) {
            return false;
        }

        // The seeder swallows its own failures, but a SQL error aborts any open Postgres
        // transaction and turns its COMMIT into a ROLLBACK, so it runs after this one commits.
        if ($this->seedsSamples($user, $workspace)) {
            $this->onboardSeeder->run($user, $workspace, $useCase->getFixtureSet());
        }

        $this->facts->forget($workspace);

        SyncSubscriberJob::dispatchFor((string) $workspace->user_id);

        return true;
    }

    private function seedsSamples(User $user, Workspace $workspace): bool
    {
        return $workspace->isPersonalWorkspace()
            && Feature::active(OnboardSeed::class)
            && ! $this->facts->hasConnectedMailbox($user, $workspace);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validate(array $input): void
    {
        Validator::make($input, [
            'onboarding_use_case' => ['required', 'string', Rule::enum(OnboardingUseCase::class)],
            'onboarding_context' => ['nullable', 'array'],
            'onboarding_context.*' => ['string'],
            'onboarding_other_use_case' => ['nullable', 'string', 'max:120'],
        ])
            ->after(function (ValidatorInstance $validator) use ($input): void {
                $this->validateContext($validator, $input);
            })
            ->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateContext(ValidatorInstance $validator, array $input): void
    {
        $useCase = OnboardingUseCase::tryFrom((string) ($input['onboarding_use_case'] ?? ''));

        if (! $useCase instanceof OnboardingUseCase) {
            return;
        }

        $subOptions = $useCase->getSubOptions();

        if ($subOptions === []) {
            return;
        }

        $context = $input['onboarding_context'] ?? null;

        if (! is_array($context) || $context === []) {
            $validator->errors()->add(
                'onboarding_context',
                __('filament/pages/workspaces.create_workspace.validation.context_required'),
            );

            return;
        }

        foreach ($context as $value) {
            if (! is_string($value) || ! array_key_exists($value, $subOptions)) {
                $validator->errors()->add(
                    'onboarding_context',
                    __('filament/pages/workspaces.create_workspace.validation.context_invalid'),
                );

                return;
            }
        }
    }
}
