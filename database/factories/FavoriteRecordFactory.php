<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\FavoriteRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FavoriteRecord>
 */
final class FavoriteRecordFactory extends Factory
{
    protected $model = FavoriteRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_type' => (new Company)->getMorphClass(),
            'record_id' => Company::factory(),
            'favorite_folder_id' => null,
            'position' => 1,
        ];
    }
}
