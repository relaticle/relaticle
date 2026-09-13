<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInvitation>
 */
final class WorkspaceInvitationFactory extends Factory
{
    protected $model = WorkspaceInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => fake()->randomElement([WorkspaceRole::Admin->value, WorkspaceRole::Editor->value]),
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
