<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Opportunity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
final class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(),
            'team_id' => Team::factory(),
        ];
    }
}
