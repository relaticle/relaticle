<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLabel;

/**
 * @extends Factory<EmailLabel>
 */
final class EmailLabelFactory extends Factory
{
    protected $model = EmailLabel::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'label' => $this->faker->randomElement(['INBOX', 'SENT', 'IMPORTANT', 'STARRED']),
            'source' => 'provider',
        ];
    }

    public function ai(): static
    {
        return $this->state(fn (): array => [
            'source' => 'ai',
            'label' => $this->faker->randomElement(['Scheduling', 'Marketing', 'Invoice', 'Support', 'Sales', 'Personal']),
        ]);
    }
}
