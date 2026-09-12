<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\StartProTrial;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidTeamSlug;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;
use Laravel\Pennant\Feature;

final readonly class CreateTeam implements CreatesTeams
{
    public function __construct(private StartProTrial $startProTrial) {}

    /**
     * Validate and create a new team for the given user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Team
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

        $isFirstTeam = ! $user->ownedTeams()->exists();

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            // Null hands the handle to Team's HasSlug pass, which settles it inside
            // the insert instead of between a check and a write.
            'slug' => ['nullable', 'string', 'max:255', new ValidTeamSlug, 'unique:teams,slug'],
            'onboarding_use_case' => ['required', 'string', Rule::enum(OnboardingUseCase::class)],
            'onboarding_context' => ['nullable', 'array'],
            'onboarding_context.*' => ['string'],
            'onboarding_other_use_case' => ['nullable', 'string', 'max:120'],
            'onboarding_referral_source' => ['nullable', 'string', Rule::enum(OnboardingReferralSource::class)],
        ])
            ->after(function (ValidatorInstance $validator) use ($input): void {
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
                        __('filament/pages/teams.create_team.validation.context_required'),
                    );

                    return;
                }

                foreach ($context as $value) {
                    if (! is_string($value) || ! array_key_exists($value, $subOptions)) {
                        $validator->errors()->add(
                            'onboarding_context',
                            __('filament/pages/teams.create_team.validation.context_invalid'),
                        );

                        return;
                    }
                }
            })
            ->validateWithBag('createTeam');

        $useCase = OnboardingUseCase::from((string) $input['onboarding_use_case']);

        event(new AddingTeam($user));

        $team = new Team([
            'name' => $input['name'],
            'slug' => $input['slug'] ?? null,
            'personal_team' => $isFirstTeam,
            'onboarding_use_case' => $useCase,
            'onboarding_context' => $useCase->getSubOptions() === []
                ? null
                : array_values($input['onboarding_context']),
            'onboarding_other_use_case' => $useCase === OnboardingUseCase::Other
                ? ($input['onboarding_other_use_case'] ?? null)
                : null,
            'onboarding_referral_source' => $input['onboarding_referral_source'] ?? null,
        ]);
        $team->plan = Plan::default();

        $user->ownedTeams()->save($team);
        $user->switchTeam($team);

        if (Feature::active(Billing::class)) {
            $this->startProTrial->execute($user, $team);
        }

        return $team;
    }
}
