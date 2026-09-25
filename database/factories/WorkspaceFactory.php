<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
final class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'user_id' => User::factory(),
            'personal_workspace' => true,
            'plan' => Plan::default()->value,
        ];
    }

    /** @phpstan-return static */
    public function configure(): static
    {
        $factory = $this->afterMaking(function (Workspace $workspace): void {
            if (blank($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name).'-'.Str::lower(Str::random(5));
            }
        })->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subMinutes($sequence->index),
            'updated_at' => now()->subMinutes($sequence->index),
        ]);

        if (config('scribe.generating')) {
            return $factory->state(['user_id' => (string) Str::ulid()]);
        }

        return $factory;
    }
}
