<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Relaticle\OnboardSeed\ModelSeeders\NoteSeeder;
use Relaticle\OnboardSeed\ModelSeeders\OpportunitySeeder;
use Relaticle\OnboardSeed\ModelSeeders\PeopleSeeder;
use Relaticle\OnboardSeed\ModelSeeders\TaskSeeder;
use Relaticle\OnboardSeed\Support\FixtureRegistry;
use Throwable;

final class OnboardSeedManager
{
    /** @var array<class-string<ModelSeederInterface>> */
    private array $entitySeederSequence = [
        CompanySeeder::class,
        PeopleSeeder::class,
        OpportunitySeeder::class,
        TaskSeeder::class,
        NoteSeeder::class,
    ];

    /** @var array<string, ModelSeederInterface> */
    private array $seeders = [];

    public function generateFor(Authenticatable $user, ?Team $team = null): bool
    {
        if (! $team instanceof Team) {
            /** @var User $user */
            $user->loadMissing('ownedTeams');
            $team = $user->personalTeam();
        }

        try {
            FixtureRegistry::clear();
            $this->initializeSeeders();

            Model::withoutEvents(function () use ($user, $team): void {
                foreach ($this->seeders as $seeder) {
                    $seeder->seed($team, $user);
                }
            });

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function initializeSeeders(): void
    {
        foreach ($this->entitySeederSequence as $seederClass) {
            $this->seeders[$seederClass] = resolve($seederClass)->initialize();
        }
    }
}
