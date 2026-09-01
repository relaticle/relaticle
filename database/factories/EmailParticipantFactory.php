<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

/**
 * @extends Factory<EmailParticipant>
 */
final class EmailParticipantFactory extends Factory
{
    protected $model = EmailParticipant::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'email_address' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'role' => EmailParticipantRole::FROM,
        ];
    }

    public function from(): static
    {
        return $this->state(fn (): array => [
            'role' => EmailParticipantRole::FROM,
        ]);
    }

    public function to(): static
    {
        return $this->state(fn (): array => [
            'role' => EmailParticipantRole::TO,
        ]);
    }

    public function cc(): static
    {
        return $this->state(fn (): array => [
            'role' => EmailParticipantRole::CC,
        ]);
    }

    public function bcc(): static
    {
        return $this->state(fn (): array => [
            'role' => EmailParticipantRole::BCC,
        ]);
    }
}
