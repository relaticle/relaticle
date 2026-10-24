<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\StartProTrial;
use App\Enums\OnboardingReferralSource;
use App\Enums\Plan;
use App\Enums\WorkspaceSetupStep;
use App\Features\Billing;
use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ValidWorkspaceSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;
use Laravel\Pennant\Feature;

final readonly class CreateWorkspace implements CreatesTeams
{
    public function __construct(private StartProTrial $startProTrial) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input, bool $onlyIfFirst = false): Workspace
    {
        return DB::transaction(function () use ($user, $input, $onlyIfFirst): Workspace {
            // Serialises one user's creations, so the first-workspace check, the cap and the insert agree.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $ownedWorkspace = $user->ownedWorkspaces()->oldest()->first();

            // A first-run wizard submitted twice, or from a second tab, opens the workspace it already made.
            if ($onlyIfFirst && $ownedWorkspace instanceof Workspace) {
                return $ownedWorkspace;
            }

            Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

            $this->validate($input);

            event(new AddingTeam($user));

            $workspace = new Workspace([
                'name' => $input['name'],
                'slug' => $input['slug'] ?? null,
                'personal_workspace' => ! $ownedWorkspace instanceof Workspace,
                'onboarding_step' => Feature::active(EmailIntegration::class) ? WorkspaceSetupStep::Email : WorkspaceSetupStep::UseCase,
                ...$this->referralAttributes($input),
            ]);
            $workspace->plan = Plan::default();

            $this->insert($user, $workspace);
            $user->switchWorkspace($workspace);

            if (Feature::active(Billing::class)) {
                $this->startProTrial->execute($user, $workspace);
            }

            return $workspace;
        });
    }

    private function insert(User $user, Workspace $workspace): void
    {
        // The savepoint turns a handle taken since validation into a field error instead of a failed transaction.
        try {
            DB::transaction(fn (): Model|false => $user->ownedWorkspaces()->save($workspace));
        } catch (UniqueConstraintViolationException $exception) {
            throw_unless($exception->index === 'workspaces_slug_unique', $exception);

            throw ValidationException::withMessages([
                'slug' => __('validation.unique', ['attribute' => __('filament/pages/workspaces.create_workspace.form.workspace_handle.label')]),
            ])->errorBag('createWorkspace');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validate(array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            // Null hands the handle to Workspace's HasSlug pass, which settles it inside
            // the insert instead of between a check and a write.
            'slug' => ['nullable', 'string', 'max:255', new ValidWorkspaceSlug, 'unique:workspaces,slug'],
            'onboarding_referral_source' => ['nullable', 'string', Rule::enum(OnboardingReferralSource::class)],
            'onboarding_referral_detail' => ['nullable', 'string'],
            'onboarding_referral_prompt' => ['nullable', 'string', 'max:200'],
        ])
            ->after(function (ValidatorInstance $validator) use ($input): void {
                $this->validateReferralDetail($validator, $input);
            })
            ->validateWithBag('createWorkspace');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateReferralDetail(ValidatorInstance $validator, array $input): void
    {
        $detail = $input['onboarding_referral_detail'] ?? null;

        if ($detail === null) {
            return;
        }

        $subOptions = $this->referralSource($input)?->getSubOptions() ?? [];

        if ($subOptions === []) {
            return;
        }

        if (is_string($detail) && array_key_exists($detail, $subOptions)) {
            return;
        }

        $validator->errors()->add(
            'onboarding_referral_detail',
            __('filament/pages/workspaces.create_workspace.validation.referral_detail_invalid'),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     onboarding_referral_source: ?OnboardingReferralSource,
     *     onboarding_referral_detail: ?string,
     *     onboarding_referral_prompt: ?string
     * }
     */
    private function referralAttributes(array $input): array
    {
        $source = $this->referralSource($input);
        $hasFollowUp = $source instanceof OnboardingReferralSource && $source->getSubOptions() !== [];

        return [
            'onboarding_referral_source' => $source,
            'onboarding_referral_detail' => $hasFollowUp ? ($input['onboarding_referral_detail'] ?? null) : null,
            'onboarding_referral_prompt' => $hasFollowUp ? ($input['onboarding_referral_prompt'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function referralSource(array $input): ?OnboardingReferralSource
    {
        return OnboardingReferralSource::tryFrom((string) ($input['onboarding_referral_source'] ?? ''));
    }
}
