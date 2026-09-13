<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed;

use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Seeder;

final class OnboardSeeder extends Seeder
{
    public function __construct(private readonly OnboardSeedManager $manager) {}

    public function run(Authenticatable $user, ?Workspace $workspace = null, string $fixtureSet = 'sales'): void
    {
        $this->manager->generateFor($user, $workspace, $fixtureSet);
    }
}
