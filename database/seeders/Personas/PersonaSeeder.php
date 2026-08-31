<?php

declare(strict_types=1);

namespace Database\Seeders\Personas;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
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
     * @return array{persona: Persona, team: Team, billing: string, note: string}
     */
    public function seed(Persona $persona): array
    {
        $user = $this->account($persona->email, $persona->name);
        $team = $this->workspace($user, $persona);

        $this->members($team, $persona);

        // Billing first: the allowance depends on the subscription Stripe just
        // wrote, and a past-due workspace refills at the Free tier.
        $note = $persona->needsStripe() ? $this->bill($team, $persona) : '';

        $this->allowance($team);

        return [
            'persona' => $persona,
            'team' => $team,
            'note' => $note,
            'billing' => Team::query()->with('subscriptions')->findOrFail($team->getKey())->billingStatus()->value,
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
    private function bill(Team $team, Persona $persona): string
    {
        if (! $this->stripe->available()) {
            return 'no test-mode STRIPE_SECRET, billed nothing';
        }

        try {
            $this->stripe->subscribe($team, $persona);

            return '';
        } catch (Throwable $e) {
            return 'Stripe failed: '.Str::limit($e->getMessage(), 70);
        }
    }

    /**
     * Give the workspace the credit allowance its plan actually grants.
     *
     * The balance is created when the team is, before the persona's plan is
     * force-filled, so it holds the Free allowance no matter which plan the
     * persona claims. Left alone, a Pro persona shows a Pro allowance on the
     * billing page while holding a Free one, which is the exact divergence
     * these fixtures exist to make visible rather than reproduce.
     */
    private function allowance(Team $team): void
    {
        resolve(CreditService::class)->resetPeriod($team->refresh()->load('subscriptions'));
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
    private function workspace(User $user, Persona $persona): Team
    {
        $team = $user->ownedTeams()->where('personal_team', true)->first()
            ?? $this->createWorkspace($user, $persona);

        $team->forceFill([
            'name' => $persona->workspace,
            'slug' => Str::slug($persona->workspace),
            ...$this->resolveDates($persona->team),
        ])->save();

        $user->forceFill(['current_team_id' => $team->getKey()])->save();

        return $team;
    }

    /**
     * Teammates, so role-scoped behaviour has somebody to be scoped to.
     * `syncWithoutDetaching` keeps this idempotent without wiping a membership
     * someone added by hand while testing.
     */
    private function members(Team $team, Persona $persona): void
    {
        foreach ($persona->members as $member) {
            $user = $this->account($member['email'], Str::headline(Str::before($member['email'], '@')));

            $team->users()->syncWithoutDetaching([$user->getKey() => ['role' => $member['role']]]);
        }
    }

    /**
     * Creating a personal team fires TeamCreated, and the app's own listener
     * seeds custom fields plus the CRM fixture set for the workspace's
     * onboarding use case. So the use case is set at creation time and the app
     * populates the workspace exactly as it would for a real signup.
     *
     * A persona with no use case wants an empty workspace, which means
     * suppressing that listener rather than deleting rows after the fact.
     */
    private function createWorkspace(User $user, Persona $persona): Team
    {
        $attributes = [
            'user_id' => $user->getKey(),
            'personal_team' => true,
            'name' => $persona->workspace,
            'onboarding_use_case' => $persona->useCase,
        ];

        if ($persona->wantsRecords()) {
            return Team::factory()->create($attributes);
        }

        return $this->withoutOnboardSeed(fn (): Team => Team::factory()->create($attributes));
    }

    /**
     * The OnboardSeed feature reads config, but Pennant memoises the resolved
     * value for the request, so the cache has to be flushed on both sides of the
     * change or the flip is silently ignored.
     *
     * @param  callable(): Team  $callback
     */
    private function withoutOnboardSeed(callable $callback): Team
    {
        $key = 'relaticle.features.onboard_seed';
        $previous = config($key);

        config([$key => false]);
        Feature::flushCache();

        try {
            return $callback();
        } finally {
            config([$key => $previous]);
            Feature::flushCache();
        }
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
