<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'account_owner_id' => User::factory(),
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
            return $factory->state([
                'account_owner_id' => (string) Str::ulid(),
                'team_id' => (string) Str::ulid(),
            ]);
        }

        return $factory;
    }
}
