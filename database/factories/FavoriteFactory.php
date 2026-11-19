<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FavoriteKind;
use App\Models\Company;
use App\Models\Favorite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * @extends Factory<Favorite>
 */
final class FavoriteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => FavoriteKind::Record,
            'record_type' => (new Company)->getMorphClass(),
            'record_id' => Company::factory(),
            'position' => DecimalPosition::forEmptyColumn(),
        ];
    }

    public function folder(string $name = 'Folder'): self
    {
        return $this->state(['kind' => FavoriteKind::Folder, 'name' => $name, 'record_type' => null, 'record_id' => null]);
    }
}
