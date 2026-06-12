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

final class DemoAccountSeeder extends Seeder
{
    public const string EMAIL = 'demo@relaticle.com';

    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->where('email', self::EMAIL)->first();

            if (! $user instanceof User) {
                /** @var User $user */
                $user = User::factory()->withPersonalTeam()->create([
                    'email' => self::EMAIL,
                    'name' => 'Relaticle Demo',
                    'password' => Hash::make('Demo!2026Relaticle'),
                    'email_verified_at' => now(),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                ]);
            } else {
                $user->forceFill([
                    'name' => 'Relaticle Demo',
                    'password' => Hash::make('Demo!2026Relaticle'),
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
