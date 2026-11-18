<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FavoriteFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FavoriteFolder>
 */
final class FavoriteFolderFactory extends Factory
{
    protected $model = FavoriteFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'position' => 1,
        ];
    }
}
