<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

/**
 * @extends Factory<EmailBlocklist>
 */
final class EmailBlocklistFactory extends Factory
{
    protected $model = EmailBlocklist::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'type' => EmailBlocklistType::EMAIL,
            'value' => $this->faker->unique()->safeEmail(),
        ];
    }

    public function email(string $address): static
    {
        return $this->state(fn (): array => [
            'type' => EmailBlocklistType::EMAIL,
            'value' => $address,
        ]);
    }

    public function domain(string $domain): static
    {
        return $this->state(fn (): array => [
            'type' => EmailBlocklistType::DOMAIN,
            'value' => $domain,
        ]);
    }
}
