<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MigrationBatch;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MigrationBatch>
 */
final class MigrationBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'status' => MigrationBatch::STATUS_PENDING,
            'entity_order' => ['companies', 'people', 'opportunities'],
            'stats' => null,
            'completed_at' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => MigrationBatch::STATUS_IN_PROGRESS,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => MigrationBatch::STATUS_COMPLETED,
            'completed_at' => now(),
            'stats' => [
                'companies' => ['successful' => 10, 'failed' => 0],
                'people' => ['successful' => 25, 'failed' => 2],
                'opportunities' => ['successful' => 15, 'failed' => 1],
            ],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => MigrationBatch::STATUS_FAILED,
        ]);
    }
}
