<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
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

    public function configure(): Factory
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->addSeconds($sequence->index),
            'updated_at' => now()->addSeconds($sequence->index),
        ]);
    }
}
