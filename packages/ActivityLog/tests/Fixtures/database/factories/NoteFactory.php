<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Note;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

final class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
