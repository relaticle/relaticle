<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvitation>
 */
final class TeamInvitationFactory extends Factory
{
    protected $model = TeamInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'role' => $this->faker->randomElement([TeamRole::Admin->value, TeamRole::Editor->value]),
            'expires_at' => now()->addDays(config('jetstream.invitation_expiry_days', 7)),
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresIn(int $days): static
    {
        return $this->state([
            'expires_at' => now()->addDays($days),
        ]);
    }

    public function withoutExpiry(): static
    {
        return $this->state([
            'expires_at' => null,
        ]);
    }

    public function withToken(string &$rawToken = ''): static
    {
        return $this->state(function (array $attributes) use (&$rawToken): array {
            $rawToken = Str::random(40);

            return ['token' => hash('sha256', $rawToken)];
        });
    }
}
