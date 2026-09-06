<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

/**
 * @extends Factory<TeamEmailBlocklist>
 */
final class TeamEmailBlocklistFactory extends Factory
{
    protected $model = TeamEmailBlocklist::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => EmailBlocklistType::EMAIL,
            'value' => $this->faker->unique()->safeEmail(),
            'enforcement_level' => EmailVisibilityEnforcement::Blocked,
            'created_by' => User::factory(),
        ];
    }

    public function protected(): static
    {
        return $this->state(fn (): array => [
            'enforcement_level' => EmailVisibilityEnforcement::Protected,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => [
            'enforcement_level' => EmailVisibilityEnforcement::Blocked,
        ]);
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
