<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed;

use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Seeder;

final class OnboardSeeder extends Seeder
{
    public function __construct(private readonly OnboardSeedManager $manager) {}

    public function run(Authenticatable $user, ?Team $team = null): void
    {
        $this->manager->generateFor($user, $team);
    }
}
