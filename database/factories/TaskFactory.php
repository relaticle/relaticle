<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

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
            'created_at' => \Illuminate\Support\Facades\Date::now(),
            'updated_at' => \Illuminate\Support\Facades\Date::now(),
            'team_id' => Team::factory(),
        ];
    }

    public function configure(): Factory
    {
        // Use minutes instead of seconds to ensure distinct timestamps
        // and avoid flaky sorting tests in fast CI environments
        return $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subMinutes($sequence->index),
            'updated_at' => now()->subMinutes($sequence->index),
        ]);
    }
}
