<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Relaticle\OnboardSeed\ModelSeeders\NoteSeeder;
use Relaticle\OnboardSeed\ModelSeeders\OpportunitySeeder;
use Relaticle\OnboardSeed\ModelSeeders\PeopleSeeder;
use Relaticle\OnboardSeed\ModelSeeders\TaskSeeder;
use Throwable;

final class OnboardSeedManager
{
    /**
     * The ordered sequence of model seeders to run
     *
     * @var array<class-string<ModelSeederInterface>>
     */
    private array $entitySeederSequence = [
        CompanySeeder::class,
        PeopleSeeder::class,
        OpportunitySeeder::class,
        TaskSeeder::class,
        NoteSeeder::class,
    ];

    /**
     * List of initialized seeders
     *
     * @var array<string, ModelSeederInterface>
     */
    private array $seeders = [];

    /**
     * Generate demo data for a user's team
     *
     * @param  Authenticatable  $user  The user to create demo data for
     * @return bool Whether the seeding was successful
     */
    public function generateFor(Authenticatable $user): bool
    {
        /** @var User $user */
        $team = $user->personalTeam();

        try {
            $this->initializeSeeders();

            $seedingContext = [];

            // Run seeders in sequence
            foreach ($this->seeders as $seeder) {
                $result = $seeder->seed($team, $user, $seedingContext);

                // Merge new context data
                $seedingContext = array_merge($seedingContext, $result);
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Initialize all seeders
     */
    private function initializeSeeders(): void
    {
        foreach ($this->entitySeederSequence as $seederClass) {
            $this->seeders[$seederClass] = resolve($seederClass)->initialize();
        }
    }
}
