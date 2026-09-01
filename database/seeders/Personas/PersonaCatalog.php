<?php

declare(strict_types=1);

namespace Database\Seeders\Personas;

use App\Enums\BillingStatus;
use App\Enums\OnboardingUseCase;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every local login, in one table.
 *
 * This is the file to edit when a new state becomes worth reproducing by hand.
 * The seeder reads it and does nothing else, so a persona cannot exist in the
 * database without a row here explaining why.
 *
 * Passwords are always `password`. These accounts only ever exist behind the
 * `isLocal()` gate in LocalSeeder.
 */
final class PersonaCatalog
{
    public const string PASSWORD = 'password';

    public const string DOMAIN = 'relaticle.test';

    /**
     * @return Collection<int, Persona>
     */
    public static function all(): Collection
    {
        return collect([
            new Persona(
                slug: 'owner',
                email: 'owner@'.self::DOMAIN,
                name: 'Olivia Owner',
                workspace: 'Acme Sales',
                purpose: 'Full CRM data, admin and editor teammates. The daily driver.',
                // Grandfathered rather than trialing: a trial lapses and then
                // every local login lands on /billing until someone re-seeds.
                expect: BillingStatus::Grandfathered,
                team: ['hosted_free_grandfathered_at' => '-1 year'],
                useCase: OnboardingUseCase::Sales,
                members: [
                    ['email' => 'admin@'.self::DOMAIN, 'role' => TeamRole::Admin->value],
                    ['email' => 'editor@'.self::DOMAIN, 'role' => TeamRole::Editor->value],
                ],
            ),

            new Persona(
                slug: 'empty',
                email: 'empty@'.self::DOMAIN,
                name: 'Evan Empty',
                workspace: 'Empty Workspace',
                purpose: 'No records at all, for walking every empty state.',
                // The only way to see every empty state without deleting rows
                // out of a populated workspace by hand.
                expect: BillingStatus::Grandfathered,
                team: ['hosted_free_grandfathered_at' => '-1 year'],
            ),

            new Persona(
                slug: 'paused',
                email: 'paused@'.self::DOMAIN,
                name: 'Pia Paused',
                workspace: 'Paused Workspace',
                purpose: 'Free plan with billing on, so every page hits the paywall.',
                // Free with billing on, so EnsureHostedWorkspaceAccess pins
                // every page to /billing. The paywall, walkable.
                expect: BillingStatus::Free,
            ),

            new Persona(
                slug: 'trial',
                email: 'trial@'.self::DOMAIN,
                name: 'Tara Trial',
                workspace: 'Trialing Workspace',
                purpose: 'Nine days left on a Pro trial, with recruiting data.',
                expect: BillingStatus::Trialing,
                team: [
                    'plan' => Plan::Pro,
                    'trial_ends_at' => '+9 days',
                    'pro_trial_used_at' => '-5 days',
                ],
                useCase: OnboardingUseCase::Recruiting,
            ),

            new Persona(
                slug: 'pro',
                email: 'pro@'.self::DOMAIN,
                name: 'Pedro Pro',
                workspace: 'Paying Workspace',
                purpose: 'A real Stripe subscription: invoices and portal resolve.',
                // A real Stripe subscription: the badge, the invoice list and
                // "Open in Stripe" all resolve against the sandbox.
                expect: BillingStatus::Subscribed,
                team: ['plan' => Plan::Pro],
                useCase: OnboardingUseCase::Marketing,
                stripe: 'pm_card_visa',
            ),

            new Persona(
                slug: 'past-due',
                email: 'past-due@'.self::DOMAIN,
                name: 'Dana Pastdue',
                workspace: 'Lapsed Workspace',
                purpose: 'A real renewal that Stripe declined, so it reads past due.',
                // Reached by advancing a Stripe test clock past a renewal that
                // the card declines. There is no other way to see this state.
                expect: BillingStatus::PastDue,
                team: ['plan' => Plan::Pro],
                stripe: 'pm_card_visa',
                pastDue: true,
            ),
        ]);
    }

    /**
     * The personas that have actually been seeded. login-link creates a missing
     * user on the fly, so offering a login for an unseeded persona would
     * silently make a bare account with none of the state its row names.
     *
     * @return Collection<int, Persona>
     */
    public static function seeded(): Collection
    {
        $emails = User::query()->whereIn('email', self::all()->pluck('email'))->pluck('email');

        return self::all()->filter(fn (Persona $persona): bool => $emails->contains($persona->email))->values();
    }

    /**
     * @param  array<int, string>  $slugs
     * @return Collection<int, Persona>
     */
    public static function only(array $slugs): Collection
    {
        return $slugs === []
            ? self::all()
            : self::all()->filter(fn (Persona $persona): bool => in_array($persona->slug, $slugs, true))->values();
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return self::all()->pluck('slug')->all();
    }
}
