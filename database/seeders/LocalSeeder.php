<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingStatus;
use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class LocalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command->info('Skipping local seeding as the environment is not local.');

            return;
        }

        $this->call(SystemAdministratorSeeder::class);

        $user = User::factory()
            ->withPersonalTeam()
            ->create([
                'name' => 'Manuk Minasyan',
                'email' => 'manuk.minasyan1@gmail.com',
            ]);

        $teamId = $user->personalTeam()->id;
        //
        //        User::factory()
        //            ->withPersonalTeam()
        //            ->create([
        //                'name' => 'Test User',
        //                'email' => 'test@example.com',
        //            ]);
        //
        //        // Create 10 Test Users
        User::factory()
            ->count(10)
            ->create()
            ->after(function (User $user) use ($teamId): void {
                // Assign the user to the personal team.
                $user->teams()->attach($teamId, [
                    'role' => 'member',
                ]);
            });

        $this->seedBillingStatusFixture();
        $this->topUpAiCreditsForLocalTeams();
        $this->seedViewerTimezoneBoundaryFixture();
        //
        //        // Set the current user and tenant.
        //        Auth::setUser($user);
        //        Filament::setTenant($user->personalTeam());
        //
        //        $customFields = CustomField::query()
        //            ->whereIn('code', ['icp', 'stage', 'domain_name'])
        //            ->get()
        //            ->keyBy('code');
        //
        //        Company::factory()
        //            ->for($user->personalTeam(), 'team')
        //            ->count(50)
        //            ->afterCreating(function (Company $company) use ($customFields): void {
        //                $company->saveCustomFieldValue($customFields->get('domain_name'), 'https://'.fake()->domainName());
        //                $company->saveCustomFieldValue($customFields->get('icp'), fake()->boolean(70));
        //            })
        //            ->create();
        //
        //        // Create people.
        //        People::factory()
        //            ->for($user->personalTeam(), 'team')
        //            ->for($user->currentTeam->companies->random(), 'company')
        //            ->state(new Sequence(
        //                fn (Sequence $sequence): array => ['company_id' => $user->personalTeam()->companies->random()->id]
        //            ))
        //            ->count(500)->create();
        //
        //        // Create opportunities.
        //        Opportunity::factory()->for($user->personalTeam(), 'team')
        //            ->count(150)
        //            ->afterCreating(function (Opportunity $opportunity) use ($customFields): void {
        //                $opportunity->saveCustomFieldValue($customFields->get('stage'), $customFields->get('stage')->options->random()->id);
        //            })
        //            ->create();
    }

    /**
     * One workspace per BillingStatus plus a subscription per Stripe status, so
     * every badge, tooltip and filter option in the sysadmin billing surfaces
     * has a row behind it without hunting for production-shaped data.
     *
     * The subscription rows are local only. Cashier reads them without calling
     * Stripe, and `past_due` and `unpaid` cannot be reached through the Stripe
     * test API at all without a test clock, so seeding them here is the only
     * way to see those badges. "Open in Stripe" will not resolve on these rows.
     */
    private function seedBillingStatusFixture(): void
    {
        if (Team::query()->where('slug', 'billing-free')->exists()) {
            $this->command?->info('Billing fixture already seeded.');

            return;
        }

        $monthly = (string) config('services.stripe.prices.pro_monthly', 'price_local_pro_monthly');
        $yearly = (string) config('services.stripe.prices.pro_yearly', 'price_local_pro_yearly');

        $subscribed = $this->billingWorkspace('Subscribed', ['plan' => Plan::Pro, 'stripe_id' => 'cus_local_subscribed']);
        Subscription::factory()->active()->withPrice($monthly)->create(['team_id' => $subscribed->getKey()]);

        $pastDue = $this->billingWorkspace('Past due', ['plan' => Plan::Pro, 'stripe_id' => 'cus_local_past_due']);
        Subscription::factory()->pastDue()->withPrice($yearly)->create(['team_id' => $pastDue->getKey()]);

        $this->billingWorkspace('Trialing', [
            'plan' => Plan::Pro,
            'trial_ends_at' => now()->addDays(9),
            'pro_trial_used_at' => now()->subDays(5),
        ]);

        $this->billingWorkspace('Enterprise', ['plan' => Plan::Enterprise]);

        $this->billingWorkspace('Granted', ['plan' => Plan::Pro]);

        $this->billingWorkspace('Grandfathered', ['hosted_free_grandfathered_at' => now()->subYear()]);

        $this->billingWorkspace('Free');

        $this->seedSupersededSubscriptionWorkspace($monthly);
        $this->seedSubscriptionHistoryWorkspace($monthly);

        $seeded = collect(BillingStatus::cases())
            ->map(fn (BillingStatus $status): string => $status->getLabel())
            ->join(', ', ' and ');

        $this->command?->info("Seeded a billing workspace for each of: {$seeded}.");
    }

    /**
     * The case `Team::latestDefaultSubscription()` exists for: a lapsed
     * subscription still on file under a live one. It must read Pro, not Past
     * due, on every surface and in the Billing filter.
     */
    private function seedSupersededSubscriptionWorkspace(string $price): void
    {
        $team = $this->billingWorkspace('Resubscribed', ['plan' => Plan::Pro, 'stripe_id' => 'cus_local_resubscribed']);

        Subscription::factory()->pastDue()->withPrice($price)->create([
            'team_id' => $team->getKey(),
            'created_at' => now()->subMonths(4),
            'updated_at' => now()->subMonths(4),
            'ends_at' => now()->subMonths(3),
        ]);

        Subscription::factory()->active()->withPrice($price)->create([
            'team_id' => $team->getKey(),
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);
    }

    /**
     * The statuses no other fixture workspace produces, as one workspace's
     * billing history, so the Subscriptions table has a row for every option in
     * its status filter.
     */
    private function seedSubscriptionHistoryWorkspace(string $price): void
    {
        $team = $this->billingWorkspace('History', ['stripe_id' => 'cus_local_history']);

        $history = [
            10 => Subscription::factory()->incompleteAndExpired(),
            9 => Subscription::factory()->incomplete(),
            8 => Subscription::factory()->trialing(now()->subMonths(8)->addDays(14)),
            // Cashier ships no `paused` state; Stripe writes it when a trial ends
            // with no payment method on file.
            7 => Subscription::factory()->state(['stripe_status' => 'paused']),
            6 => Subscription::factory()->unpaid(),
            5 => Subscription::factory()->canceled()->state(['ends_at' => now()->subMonths(4)]),
        ];

        foreach ($history as $monthsAgo => $factory) {
            $factory->withPrice($price)->create([
                'team_id' => $team->getKey(),
                'created_at' => now()->subMonths($monthsAgo),
                'updated_at' => now()->subMonths($monthsAgo),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billingWorkspace(string $label, array $attributes = []): Team
    {
        $slug = 'billing-'.Str::slug($label);

        $owner = User::factory()->withTeam()->create([
            'name' => "{$label} Owner",
            'email' => "{$slug}@example.test",
        ]);

        $team = $owner->currentTeam;
        $team->forceFill(['name' => "Billing · {$label}", 'slug' => $slug, ...$attributes])->save();

        return $team;
    }

    /**
     * Fixture for walking the sysadmin panel's timezone behaviour by hand.
     *
     * Every row lands on one of two instants on the same UTC day: 19:00 UTC is
     * 23:00 that evening in Asia/Yerevan, and 21:00 UTC is 01:00 the NEXT
     * morning there. So an administrator on Yerevan must see the pair split
     * across two calendar days while one on UTC sees them on the same day, and
     * the 21:00 row must appear in the Yerevan administrator's "today".
     *
     * Paired with two administrators, one zoned and one not, this is what makes
     * a wrong answer visible rather than merely plausible.
     */
    private function seedViewerTimezoneBoundaryFixture(): void
    {
        $evening = Date::now('UTC')->subDay()->setTime(19, 0);
        $afterMidnight = Date::now('UTC')->subDay()->setTime(21, 0);

        SystemAdministrator::query()->firstOrCreate(['email' => 'yerevan@relaticle.com'], [
            'name' => 'Yerevan Administrator',
            'password' => bcrypt('password'),
            'role' => SystemAdministratorRole::SuperAdministrator,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Yerevan',
        ]);

        SystemAdministrator::query()->firstOrCreate(['email' => 'utc@relaticle.com'], [
            'name' => 'UTC Administrator',
            'password' => bcrypt('password'),
            'role' => SystemAdministratorRole::SuperAdministrator,
            'email_verified_at' => now(),
            'timezone' => null,
        ]);

        foreach (['Evening' => $evening, 'AfterMidnight' => $afterMidnight] as $label => $instant) {
            $owner = User::factory()->withTeam()->create([
                'name' => "Boundary {$label}",
                'email' => 'boundary-'.mb_strtolower($label).'-'.Str::lower(Str::ulid()).'@example.test',
                'created_at' => $instant,
                'updated_at' => $instant,
            ]);

            $team = $owner->currentTeam;
            $team->forceFill([
                'name' => "Boundary {$label} Team",
                'personal_team' => false,
                'created_at' => $instant,
                'updated_at' => $instant,
            ])->save();

            foreach ([Company::class, People::class, Task::class, Note::class, Opportunity::class] as $model) {
                $model::withoutEvents(fn () => $model::factory()->for($team)->create([
                    'creator_id' => $owner->id,
                    'creation_source' => CreationSource::WEB,
                    'created_at' => $instant,
                    'updated_at' => $instant,
                ]));
            }

            Activity::query()->withoutGlobalScope(TeamScope::class)->create([
                'log_name' => 'crm',
                'description' => "boundary {$label}",
                'event' => 'updated',
                'subject_type' => 'company',
                'subject_id' => (string) Str::ulid(),
                'causer_type' => 'user',
                'causer_id' => $owner->id,
                'team_id' => $team->id,
                'properties' => [],
                'created_at' => $instant,
            ]);
        }

        $this->command?->info('Seeded viewer-timezone boundary fixture at 19:00 and 21:00 UTC yesterday.');
    }

    /**
     * Bump every existing AI credit balance to a developer-friendly ceiling so
     * local chat sessions don't hit the free plan's 100-credit allowance while
     * exercising features. Production runs this seeder behind the isLocal()
     * gate at the top of run(), so this only ever fires in dev.
     */
    private function topUpAiCreditsForLocalTeams(): void
    {
        $target = 1_000_000;

        AiCreditBalance::query()
            ->where('credits_remaining', '<', $target)
            ->cursor()
            ->each(function (AiCreditBalance $balance) use ($target): void {
                $delta = $target - $balance->credits_remaining;

                $balance->update(['credits_remaining' => $target]);

                AiCreditTransaction::query()->create([
                    'team_id' => $balance->team_id,
                    'user_id' => null,
                    'conversation_id' => null,
                    'idempotency_key' => 'local-dev-grant-'.Str::ulid(),
                    'type' => AiCreditType::Adjustment,
                    'model' => 'system',
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'credits_charged' => 0,
                    'metadata' => [
                        'action' => 'local_dev_grant',
                        'delta' => $delta,
                        'target' => $target,
                    ],
                    'created_at' => now(),
                ]);
            });
    }
}
