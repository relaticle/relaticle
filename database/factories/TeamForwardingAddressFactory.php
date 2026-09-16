<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;

/**
 * @extends Factory<TeamForwardingAddress>
 */
final class TeamForwardingAddressFactory extends Factory
{
    protected $model = TeamForwardingAddress::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'local_part' => fake()->unique()->slug(2),
        ];
    }
}
