<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\People>
 */
final class PeopleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'team_id' => Team::factory(),
        ];
    }

    public function configure(): Factory
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subSeconds($sequence->index),
            'updated_at' => now()->subSeconds($sequence->index),
        ]);
    }
}
