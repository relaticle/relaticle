<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Locales;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Relaticle\OnboardSeed\ModelSeeders\NoteSeeder;
use Relaticle\OnboardSeed\ModelSeeders\OpportunitySeeder;
use Relaticle\OnboardSeed\ModelSeeders\PeopleSeeder;
use Relaticle\OnboardSeed\ModelSeeders\TaskSeeder;
use Relaticle\OnboardSeed\Support\FixtureLoader;
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

    public function generateFor(Authenticatable $user, ?Workspace $workspace = null, string $fixtureSet = 'sales'): bool
    {
        if (! $workspace instanceof Workspace) {
            /** @var User $user */
            $user->loadMissing('ownedWorkspaces');
            $workspace = $user->personalWorkspace();
        }

        $locale = $user instanceof User ? $user->effectiveLocale() : (string) config('app.default_locale');

        try {
            FixtureRegistry::clear();
            FixtureLoader::setFixtureSet($fixtureSet);
            FixtureLoader::setLocale($locale);
            $this->initializeSeeders();

            Locales::within($locale, fn () => DB::transaction(function () use ($user, $workspace): void {
                Model::withoutEvents(function () use ($user, $workspace): void {
                    foreach ($this->seeders as $seeder) {
                        $seeder->seed($workspace, $user);
                    }
                });
            }));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        } finally {
            FixtureLoader::reset();
        }
    }

    private function initializeSeeders(): void
    {
        foreach ($this->entitySeederSequence as $seederClass) {
            $this->seeders[$seederClass] = resolve($seederClass)->initialize();
        }
    }
}
