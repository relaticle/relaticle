<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class DemoAccountSeeder extends Seeder
{
    public const string EMAIL = 'demo@relaticle.com';

    public function run(): void
    {
        $password = (string) config('services.demo_account.password');

        throw_if($password === '', RuntimeException::class, 'DEMO_ACCOUNT_PASSWORD is not set; refusing to seed the demo account with a repository-visible password.');

        DB::transaction(function () use ($password): void {
            $user = User::query()->where('email', self::EMAIL)->first();

            if (! $user instanceof User) {
                /** @var User $user */
                $user = User::factory()->withPersonalTeam()->create([
                    'email' => self::EMAIL,
                    'name' => 'Relaticle Demo',
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                ]);
            } else {
                $user->forceFill([
                    'name' => 'Relaticle Demo',
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                ])->save();
            }

            /** @var Team|null $team */
            $team = $user->personalTeam();

            if ($team === null) {
                $this->command->error('Demo user has no personal team — onboarding may have failed.');

                return;
            }

            // The factory builds the team directly rather than through
            // App\Actions\Jetstream\CreateTeam, so it never receives the Pro trial a real
            // signup gets. Without hosted access the workspace is paused and every REST
            // API and MCP call answers 402, which is precisely what directory reviewers
            // would hit. Grandfather it instead of trialling so it never lapses.
            if ($team->hosted_free_grandfathered_at === null) {
                $team->forceFill(['hosted_free_grandfathered_at' => now()])->save();
            }

            $taskIdsForTeam = Task::query()->where('team_id', $team->getKey())->pluck('id');
            $noteIdsForTeam = Note::query()->where('team_id', $team->getKey())->pluck('id');

            DB::table('taskables')->whereIn('task_id', $taskIdsForTeam)->delete();
            DB::table('noteables')->whereIn('note_id', $noteIdsForTeam)->delete();

            Company::query()->where('team_id', $team->getKey())->forceDelete();
            People::query()->where('team_id', $team->getKey())->forceDelete();
            Opportunity::query()->where('team_id', $team->getKey())->forceDelete();
            Task::query()->where('team_id', $team->getKey())->forceDelete();
            Note::query()->where('team_id', $team->getKey())->forceDelete();

            $companies = Company::factory()
                ->for($team)
                ->count(8)
                ->create(['account_owner_id' => $user->getKey()]);

            People::factory()
                ->for($team)
                ->count(20)
                ->create([
                    'company_id' => fn () => $companies->random()->getKey(),
                ]);

            Opportunity::factory()->for($team)->count(12)->create();
            Task::factory()->for($team)->count(15)->create();
            Note::factory()->for($team)->count(10)->create();
        });
    }
}
