<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

/**
 * @extends Factory<ProtectedRecipient>
 */
final class ProtectedRecipientFactory extends Factory
{
    protected $model = ProtectedRecipient::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => 'email',
            'value' => $this->faker->unique()->safeEmail(),
            'created_by' => User::factory(),
        ];
    }

    public function email(string $address): static
    {
        return $this->state(fn (): array => [
            'type' => 'email',
            'value' => $address,
        ]);
    }

    public function domain(string $domain): static
    {
        return $this->state(fn (): array => [
            'type' => 'domain',
            'value' => $domain,
        ]);
    }
}
