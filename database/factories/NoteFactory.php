<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Note;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Str;

/**
 * @extends Factory<Note>
 */
final class NoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
            'team_id' => Team::factory(),
        ];
    }

    /** @phpstan-return static */
    public function configure(): static
    {
        $factory = $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subMinutes($sequence->index),
            'updated_at' => now()->subMinutes($sequence->index),
        ]);

        if (config('scribe.generating')) {
            return $factory->state(['team_id' => (string) Str::ulid()]);
        }

        return $factory;
    }
}
