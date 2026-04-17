<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

final class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return ['name' => fake()->name()];
    }
}
