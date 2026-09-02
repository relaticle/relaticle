<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * Fixture for walking the sysadmin panel's timezone behaviour by hand.
 *
 * Every row lands on one of two instants on the same UTC day: 19:00 UTC is
 * 23:00 that evening in Asia/Yerevan, and 21:00 UTC is 01:00 the NEXT morning
 * there. So an administrator on Yerevan must see the pair split across two
 * calendar days while one on UTC sees them on the same day, and the 21:00 row
 * must appear in the Yerevan administrator's "today".
 *
 * Paired with two administrators, one zoned and one not, this is what makes a
 * wrong answer visible rather than merely plausible.
 *
 * Re-runnable like the rest of local seeding: the workspaces are keyed by name
 * and their rows are rebuilt against today's clock, so the two instants stay
 * "yesterday" however long ago you first seeded.
 */
final class ViewerTimezoneBoundarySeeder extends Seeder
{
    private const string PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $this->administrator('yerevan@relaticle.com', 'Yerevan Administrator', 'Asia/Yerevan');
        $this->administrator('utc@relaticle.com', 'UTC Administrator', null);

        $instants = [
            'Evening' => Date::now('UTC')->subDay()->setTime(19, 0),
            'AfterMidnight' => Date::now('UTC')->subDay()->setTime(21, 0),
        ];

        foreach ($instants as $label => $instant) {
            $this->boundaryWorkspace($label, $instant);
        }

        $this->command?->info('Seeded viewer-timezone boundary fixture at 19:00 and 21:00 UTC yesterday.');
    }

    private function administrator(string $email, string $name, ?string $timezone): void
    {
        SystemAdministrator::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => bcrypt(self::PASSWORD),
            'role' => SystemAdministratorRole::SuperAdministrator,
            'email_verified_at' => now(),
            'timezone' => $timezone,
        ]);
    }

    /**
     * One workspace per instant, rebuilt from scratch each run so the rows stay
     * pinned to yesterday rather than drifting into the past.
     */
    private function boundaryWorkspace(string $label, Carbon $instant): void
    {
        $email = 'boundary-'.mb_strtolower($label).'@example.test';

        $owner = User::query()->firstOrNew(['email' => $email]);

        if (! $owner->exists) {
            $owner->forceFill(User::factory()->raw(['email' => $email]))->save();
        }

        $owner->forceFill([
            'name' => "Boundary {$label}",
            'created_at' => $instant,
            'updated_at' => $instant,
        ])->save();

        $team = $owner->ownedTeams()->where('name', "Boundary {$label} Team")->first()
            ?? Team::factory()->create(['user_id' => $owner->getKey(), 'name' => "Boundary {$label} Team"]);

        $team->forceFill([
            'personal_team' => false,
            'created_at' => $instant,
            'updated_at' => $instant,
        ])->save();

        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        foreach ([Company::class, People::class, Task::class, Note::class, Opportunity::class] as $model) {
            $model::query()->where('team_id', $team->getKey())->delete();

            $model::withoutEvents(fn () => $model::factory()->for($team)->create([
                'creator_id' => $owner->getKey(),
                'creation_source' => CreationSource::WEB,
                'created_at' => $instant,
                'updated_at' => $instant,
            ]));
        }

        Activity::query()->withoutGlobalScope(TeamScope::class)->where('team_id', $team->getKey())->delete();

        Activity::query()->withoutGlobalScope(TeamScope::class)->create([
            'log_name' => 'crm',
            'description' => "boundary {$label}",
            'event' => 'updated',
            'subject_type' => 'company',
            'subject_id' => (string) Str::ulid(),
            'causer_type' => 'user',
            'causer_id' => $owner->getKey(),
            'team_id' => $team->getKey(),
            'properties' => [],
            'created_at' => $instant,
        ]);
    }
}
