<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\TableLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableLayout>
 */
final class TableLayoutFactory extends Factory
{
    protected $model = TableLayout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity' => CrmEntity::Company,
            'name' => fake()->unique()->words(2, true),
            'is_shared' => false,
            'state' => new TableStateData,
        ];
    }

    public function shared(): self
    {
        return $this->state(['is_shared' => true]);
    }
}
