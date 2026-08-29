<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
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
