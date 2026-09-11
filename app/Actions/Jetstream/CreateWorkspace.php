<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\StartProTrial;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\Plan;
use App\Features\Billing;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ValidWorkspaceSlug;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;
use Laravel\Pennant\Feature;

final readonly class CreateWorkspace implements CreatesTeams
{
    public function __construct(private StartProTrial $startProTrial) {}

    /**
     * Validate and create a new workspace for the given user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Workspace
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

        $isFirstWorkspace = ! $user->ownedWorkspaces()->exists();

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', new ValidWorkspaceSlug, 'unique:workspaces,slug'],
            'onboarding_use_case' => ['required', 'string', Rule::enum(OnboardingUseCase::class)],
            'onboarding_other_use_case' => ['nullable', 'string', 'max:120'],
            'onboarding_referral_source' => ['nullable', 'string', Rule::enum(OnboardingReferralSource::class)],
        ])->validateWithBag('createWorkspace');

        $useCase = OnboardingUseCase::from((string) $input['onboarding_use_case']);

        event(new AddingTeam($user));

        $workspace = new Workspace([
            'name' => $input['name'],
            'slug' => $input['slug'],
            'personal_workspace' => $isFirstWorkspace,
            'onboarding_use_case' => $useCase,
            'onboarding_other_use_case' => $useCase === OnboardingUseCase::Other
                ? ($input['onboarding_other_use_case'] ?? null)
                : null,
            'onboarding_referral_source' => $input['onboarding_referral_source'] ?? null,
        ]);
        $workspace->plan = Plan::default();

        $user->ownedWorkspaces()->save($workspace);
        $user->switchWorkspace($workspace);

        if (Feature::active(Billing::class)) {
            $this->startProTrial->execute($user, $workspace);
        }

        return $workspace;
    }
}
