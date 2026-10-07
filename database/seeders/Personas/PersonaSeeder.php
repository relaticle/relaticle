<?php

declare(strict_types=1);

namespace Database\Seeders\Personas;

use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceSetupStep;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\Chat\Services\CreditService;
use Throwable;

/**
 * Turns a Persona into a workspace you can log into.
 *
 * Idempotent by construction: every write is an upsert keyed on the persona's
 * email or slug, so running it twice converges instead of duplicating. That is
 * the property the old seeder lacked, which is why a re-run used to abort on a
 * unique email and strand every fixture behind it.
 */
final readonly class PersonaSeeder
{
    public function __construct(
        private StripeSandbox $stripe,
    ) {}

    /**
     * @return array{persona: Persona, workspace: Workspace, billing: string, note: string}
     */
    public function seed(Persona $persona): array
    {
        $user = $this->account($persona->email, $persona->name);
        $workspace = $this->workspace($user, $persona);

        $this->members($workspace, $persona);

        // Billing first: the allowance depends on the subscription Stripe just
        // wrote, and a past-due workspace refills at the Free tier.
        $note = $persona->needsStripe() ? $this->bill($workspace, $persona) : '';

        $this->allowance($workspace);

        return [
            'persona' => $persona,
            'workspace' => $workspace,
            'note' => $note,
            'billing' => Workspace::query()->with('subscriptions')->findOrFail($workspace->getKey())->billingStatus()->value,
        ];
    }

    /**
     * Bill the sandbox, reporting rather than throwing.
     *
     * Only the Stripe leg is caught: it is the one step that depends on a
     * credential and a network this checkout may not have, and a local seed must
     * still produce the four workspaces that need neither. A failure anywhere
     * else is a real defect and is left to surface.
     */
    private function bill(Workspace $workspace, Persona $persona): string
    {
        if (! $this->stripe->available()) {
            return 'no test-mode STRIPE_SECRET, billed nothing';
        }

        try {
            $this->stripe->subscribe($workspace, $persona);

            return '';
        } catch (Throwable $e) {
            return 'Stripe failed: '.Str::limit($e->getMessage(), 70);
        }
    }

    /**
     * Give the workspace the credit allowance its plan actually grants.
     *
     * The balance is created when the workspace is, before the persona's plan is
     * force-filled, so it holds the Free allowance no matter which plan the
     * persona claims. Left alone, a Pro persona shows a Pro allowance on the
     * billing page while holding a Free one, which is the exact divergence
     * these fixtures exist to make visible rather than reproduce.
     */
    private function allowance(Workspace $workspace): void
    {
        resolve(CreditService::class)->resetPeriod($workspace->refresh()->load('subscriptions'));
    }

    /**
     * The login itself. `password` everywhere, verified, so no persona is ever
     * one confirmation email away from being unusable.
     */
    private function account(string $email, string $name): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill(User::factory()->raw(['email' => $email]))->save();
        }

        $user->forceFill([
            'name' => $name,
            'password' => bcrypt(PersonaCatalog::PASSWORD),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * The persona's personal workspace, force-filled with the billing state the
     * persona exists to demonstrate. Relative dates in the catalog (`-1 year`)
     * are resolved here so the table stays declarative.
     */
    private function workspace(User $user, Persona $persona): Workspace
    {
        $workspace = $user->ownedWorkspaces()->where('personal_workspace', true)->first()
            ?? $this->createWorkspace($user, $persona);

        $workspace->forceFill([
            'name' => $persona->workspace,
            'slug' => Str::slug($persona->workspace),
            ...$this->resolveDates($persona->workspaceAttributes),
        ])->save();

        $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

        return $workspace;
    }

    /**
     * Teammates, so role-scoped behaviour has somebody to be scoped to.
     * `syncWithoutDetaching` keeps this idempotent without wiping a membership
     * someone added by hand while testing.
     */
    private function members(Workspace $workspace, Persona $persona): void
    {
        foreach ($persona->members as $member) {
            $user = $this->account($member['email'], Str::headline(Str::before($member['email'], '@')));

            $workspace->users()->syncWithoutDetaching([$user->getKey() => ['role' => $member['role']]]);
        }
    }

    /**
     * A persona with records goes through the use case step a signup uses, so it gets the
     * stage preset and fixtures from the app. A persona without a use case is never in setup.
     */
    private function createWorkspace(User $user, Persona $persona): Workspace
    {
        $attributes = [
            'user_id' => $user->getKey(),
            'personal_workspace' => true,
            'name' => $persona->workspace,
        ];

        $useCase = $persona->useCase;

        if (! $useCase instanceof OnboardingUseCase) {
            return Workspace::factory()->create($attributes);
        }

        $workspace = Workspace::factory()->create([...$attributes, 'onboarding_step' => WorkspaceSetupStep::UseCase]);

        resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, [
            'onboarding_use_case' => $useCase->value,
            'onboarding_context' => array_slice(array_keys($useCase->getSubOptions()), 0, 1),
        ], null);

        return $workspace;
    }

    /**
     * The catalog states dates as relative strings (`-1 year`) so it stays a
     * readable table. They are resolved against the clock here, by column name
     * rather than by sniffing the value, so a future string attribute cannot be
     * mistaken for a date.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolveDates(array $attributes): array
    {
        $dates = ['hosted_free_grandfathered_at', 'trial_ends_at', 'pro_trial_used_at'];

        return collect($attributes)
            ->map(fn (mixed $value, string $column): mixed => in_array($column, $dates, true) && is_string($value)
                ? Date::parse($value)
                : $value)
            ->all();
    }
}
