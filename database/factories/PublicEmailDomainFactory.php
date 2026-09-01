<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

/**
 * @extends Factory<PublicEmailDomain>
 */
final class PublicEmailDomainFactory extends Factory
{
    protected $model = PublicEmailDomain::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'domain' => $this->faker->unique()->domainName(),
        ];
    }
}
