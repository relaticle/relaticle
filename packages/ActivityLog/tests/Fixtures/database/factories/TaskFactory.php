<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Task;

final class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'title' => fake()->sentence(),
            'completed_at' => null,
        ];
    }
}
