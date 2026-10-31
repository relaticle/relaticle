<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\TablePreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TablePreference>
 */
final class TablePreferenceFactory extends Factory
{
    protected $model = TablePreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity' => CrmEntity::Company,
            'state' => new TableStateData,
        ];
    }
}
