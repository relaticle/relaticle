<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Task>
 */
final class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'team_id' => Team::factory(),
            'creator_id' => User::factory(),
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
