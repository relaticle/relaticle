<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

final class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'subject' => fake()->sentence(),
            'sent_at' => null,
            'received_at' => null,
        ];
    }
}
